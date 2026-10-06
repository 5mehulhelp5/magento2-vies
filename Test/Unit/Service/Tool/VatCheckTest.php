<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Vies\Test\Unit\Service\Tool;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Vies\Service\Tool\VatCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VatCheckTest extends TestCase
{
    private int $status = 200;

    private string $body = '';

    private ?\Throwable $failure = null;

    /** @var array<string,mixed> */
    private array $sent = [];

    private int $requests = 0;

    private function tool(): VatCheck
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('post')->willReturnCallback(function (string $url, string $payload): void {
            $this->requests++;
            if ($this->failure !== null) {
                throw $this->failure;
            }
            $this->sent = json_decode($payload, true);
        });
        $curl->method('getStatus')->willReturnCallback(fn (): int => $this->status);
        $curl->method('getBody')->willReturnCallback(fn (): string => $this->body);

        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        return new VatCheck($factory, new Json());
    }

    /**
     * @param array<string,mixed> $answer
     */
    private function viesAnswers(array $answer): void
    {
        $this->body = json_encode($answer, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function itIsGrantedPerUserAndReadsNothingFromMagento(): void
    {
        self::assertSame(Acl::MAGO_PER_USER, $this->tool()->getMagentoAcl());
        self::assertSame(Acl::MAGO_PER_USER, $this->tool()->getMagentoAcl(['vat_number' => 'NL123456789B01']));
        self::assertTrue($this->tool()->isReadOnly());
    }

    #[Test]
    public function itOnlyTakesAVatNumber(): void
    {
        $schema = $this->tool()->getParameterSchema();

        self::assertSame(['vat_number'], array_keys($schema['properties']));
        self::assertSame(['vat_number'], $schema['required']);
    }

    #[Test]
    public function itRequiresAVatNumber(): void
    {
        $result = $this->tool()->execute([]);

        self::assertSame('vies_vat_check needs a vat_number', $result['error']);
        self::assertSame(0, $this->requests);
    }

    #[Test]
    public function itRejectsANumberWithoutAMemberStatePrefixBeforeAskingVies(): void
    {
        $result = $this->tool()->execute(['vat_number' => 'US123456789']);

        self::assertStringStartsWith('Not an EU VAT number', $result['error']);
        self::assertSame(0, $this->requests);
    }

    #[Test]
    public function itNormalisesTheNumberAndMapsGreeceToEl(): void
    {
        $this->viesAnswers(['valid' => true, 'countryCode' => 'EL', 'requestDate' => '2026-10-04', 'name' => '---', 'address' => '---']);

        $result = $this->tool()->execute(['vat_number' => 'gr 123.456.789']);

        self::assertSame(['countryCode' => 'EL', 'vatNumber' => '123456789'], $this->sent);
        self::assertSame('EL123456789', $result['vat_number']);
        self::assertTrue($result['valid']);
        self::assertSame('', $result['name']);
    }

    #[Test]
    public function itReturnsNameAndAddressWhenTheMemberStateSharesThem(): void
    {
        $this->viesAnswers([
            'valid' => true,
            'countryCode' => 'NL',
            'requestDate' => '2026-10-04',
            'name' => "Example   B.V.\n",
            'address' => "Straat 1\n1234 AB Plaats",
        ]);

        $result = $this->tool()->execute(['vat_number' => 'NL123456789B01']);

        self::assertSame('Example B.V.', $result['name']);
        self::assertSame('Straat 1 1234 AB Plaats', $result['address']);
        self::assertArrayNotHasKey('order_number', $result);
    }

    #[Test]
    public function anInvalidAnswerIsNotAnError(): void
    {
        $this->viesAnswers(['valid' => false, 'countryCode' => 'DE', 'userError' => 'INVALID']);

        $result = $this->tool()->execute(['vat_number' => 'DE123456789']);

        self::assertFalse($result['valid']);
        self::assertArrayNotHasKey('error', $result);
    }

    #[Test]
    public function aMemberStateThatDoesNotAnswerIsAnErrorNotAnInvalidNumber(): void
    {
        $this->viesAnswers(['valid' => false, 'userError' => 'MS_UNAVAILABLE']);

        $result = $this->tool()->execute(['vat_number' => 'DE123456789']);

        self::assertSame('VIES could not answer: MS_UNAVAILABLE', $result['error']);
        self::assertArrayNotHasKey('valid', $result);
    }

    #[Test]
    public function aFailedEnvelopeOnlyPassesItsErrorCodeOn(): void
    {
        $this->viesAnswers([
            'actionSucceed' => false,
            'errorWrappers' => [['error' => 'GLOBAL_MAX_CONCURRENT_REQ', 'message' => 'Free text <script>']],
        ]);

        $result = $this->tool()->execute(['vat_number' => 'DE123456789']);

        self::assertSame('VIES could not answer: GLOBAL_MAX_CONCURRENT_REQ', $result['error']);
    }

    #[Test]
    public function aNonOkStatusIsReported(): void
    {
        $this->status = 503;

        $result = $this->tool()->execute(['vat_number' => 'DE123456789']);

        self::assertSame('VIES answered with HTTP 503', $result['error']);
    }

    #[Test]
    public function anUnreachableRegisterIsReported(): void
    {
        $this->failure = new \RuntimeException('timed out');

        $result = $this->tool()->execute(['vat_number' => 'DE123456789']);

        self::assertSame('Could not reach VIES', $result['error']);
    }

    #[Test]
    public function aValidUserErrorIsAnAnswerNotAnError(): void
    {
        $this->viesAnswers(['valid' => true, 'countryCode' => 'DE', 'userError' => 'VALID']);

        $result = $this->tool()->execute(['vat_number' => 'DE123456789']);

        self::assertTrue($result['valid']);
        self::assertArrayNotHasKey('error', $result);
    }

    #[Test]
    public function aFreeTextUserErrorDoesNotReachTheModel(): void
    {
        $this->viesAnswers(['valid' => false, 'userError' => 'Service down, contact <script>']);

        $result = $this->tool()->execute(['vat_number' => 'DE123456789']);

        self::assertSame('VIES could not answer: unknown error', $result['error']);
    }

    #[Test]
    public function unreadableJsonIsReported(): void
    {
        $this->body = 'not json';

        $result = $this->tool()->execute(['vat_number' => 'DE123456789']);

        self::assertSame('VIES returned something unreadable', $result['error']);
    }

    #[Test]
    public function everyReturnedFieldIsClassifiedAndTheNumberIsMasked(): void
    {
        $this->viesAnswers(['valid' => true, 'countryCode' => 'NL', 'requestDate' => '2026-10-04', 'name' => 'X', 'address' => 'Y']);
        $tool = $this->tool();

        $result = $tool->execute(['vat_number' => 'NL123456789B01']);
        $classification = $tool->getFieldClassification();

        self::assertSame([], array_diff(array_keys($result), array_keys($classification)));
        self::assertSame([PiiClass::TOKENISE, 'vat'], $classification['vat_number']);
        self::assertSame([PiiClass::TOKENISE, 'name'], $classification['name']);
        self::assertSame([PiiClass::TOKENISE, 'address'], $classification['address']);
    }
}
