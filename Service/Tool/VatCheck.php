<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Vies\Service\Tool;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Validates an EU VAT number against the European Commission's VIES service, which needs no key.
 *
 * This is the smallest complete example of a skill that lives outside MagoAssistant_Mago: one class
 * implementing ToolInterface, one di.xml entry, nothing else. The eight methods below are the whole
 * contract, and the comments on them are the parts that are easy to get wrong.
 */
class VatCheck implements ToolInterface
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
    private const TIMEOUT_SECONDS = 15;

    /**
     * The prefixes VIES accepts. Greece is EL in VIES but GR in ISO 3166 (and in Magento), and XI is
     * Northern Ireland, which VIES still covers.
     */
    private const MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];
    private const ISO_TO_VIES = ['GR' => 'EL'];

    /**
     * What VIES puts in name and address when a member state does not share them.
     */
    private const NOT_SHARED = '---';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $json
    ) {
    }

    /**
     * What the model calls. It matches the name in di.xml.
     */
    public function getName(): string
    {
        return 'vies_vat_check';
    }

    /**
     * The model picks a tool by this sentence, so say what it does and what it does not. A
     * description that promises more than the tool delivers is the most common reason a model
     * chooses the wrong one.
     */
    public function getDescription(): string
    {
        return 'Validate an EU VAT number against VIES, the European Commission register. Pass the '
            . 'number itself. To check the number on an order, first read the order with order_manager '
            . '(get_document) and pass its vat_id. Answers whether the number is registered and, if the '
            . 'member state shares them, the registered company name and address.';
    }

    /**
     * JSON Schema. Note that the model fills in every parameter it is shown, whether or not the
     * question called for one, so only offer parameters that are always safe to receive.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vat_number' => [
                    'type' => 'string',
                    'description' => 'VAT number including its country prefix, e.g. "NL123456789B01"',
                ],
            ],
            'required' => ['vat_number'],
        ];
    }

    /**
     * This tool reads nothing from Magento, so no Magento resource fits it. MAGO_PER_USER hands the
     * decision to the assistant's own per-user grants: the tool is off for every admin until it is
     * allowed under Stores > Admin Assistant > Skills & Permissions. The data a check needs (the VAT
     * number on an order) is read through order_manager, which carries the Sales resource itself.
     *
     * @param array<string,mixed> $input
     */
    public function getMagentoAcl(array $input = []): string
    {
        return Acl::MAGO_PER_USER;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $input
     */
    public function isReadOnlyAction(array $input): bool
    {
        return true;
    }

    /**
     * How each field of the result may cross to the model. Undeclared is never public: a field left
     * out here is dropped before the model sees it, silently, so this list has to match what
     * execute() actually returns.
     *
     * VIES answers with the registered name and address. For a company that is public record, but
     * for a sole trader it is a person's name and home address, so both are masked rather than sent:
     * the model is handed mago://name_1 and the panel shows the administrator the real value. A sole
     * trader's VAT number identifies a person too, so it is masked the same way.
     *
     * Masking also matters for anything a tool echoes back. A read-only tool receives its arguments
     * with masked values already filled in, so an echoed argument declared PUBLIC would hand the
     * model the real value behind a token it was given.
     *
     * @return array<string, array<int, string>>
     */
    public function getFieldClassification(string $action = ''): array
    {
        return [
            'valid' => [PiiClass::PUBLIC],
            'vat_number' => [PiiClass::TOKENISE, 'vat'],
            'country_code' => [PiiClass::PUBLIC],
            'request_date' => [PiiClass::PUBLIC],
            'name' => [PiiClass::TOKENISE, 'name'],
            'address' => [PiiClass::TOKENISE, 'address'],
        ];
    }

    /**
     * Instructions reach the model after this tool has already run once, so they are the place to
     * say how to present an answer, not how to call the tool. Anything about which arguments to
     * pass belongs in getDescription() or the parameter schema, which the model reads first.
     */
    public function getInstructions(): string
    {
        return 'An invalid VAT number is not proof of fraud, and some member states do not return a '
            . 'name or address at all. When the result is an error, the register could not answer: '
            . 'say so, and do not call the number invalid. Say what the register answered rather '
            . 'than what it implies.';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $vatNumber = $this->normalise($this->stringParam($params, 'vat_number'));
        if ($vatNumber === '') {
            return ['error' => 'vies_vat_check needs a vat_number'];
        }

        // Check the shape before anything leaves the shop. The input is not echoed in the error:
        // it may be a masked value the model was never meant to read back.
        $countryCode = self::ISO_TO_VIES[substr($vatNumber, 0, 2)] ?? substr($vatNumber, 0, 2);
        $number = substr($vatNumber, 2);
        if (!in_array($countryCode, self::MEMBER_STATES, true)
            || preg_match('/^[A-Z0-9]{2,12}$/', $number) !== 1
        ) {
            return ['error' => 'Not an EU VAT number: it must start with a member-state prefix such as NL or '
                . 'DE, followed by 2 to 12 letters or digits'];
        }

        $result = $this->ask($countryCode, $number);
        if (isset($result['error'])) {
            return $result;
        }

        return [
            'valid' => (bool)($result['valid'] ?? false),
            'vat_number' => $countryCode . $number,
            'country_code' => $this->tidy($result['countryCode'] ?? ''),
            'request_date' => $this->tidy($result['requestDate'] ?? ''),
            'name' => $this->tidy($result['name'] ?? ''),
            'address' => $this->tidy($result['address'] ?? ''),
        ];
    }

    /**
     * VIES reports most failures with HTTP 200: an actionSucceed=false envelope, or a userError
     * other than VALID or INVALID. Read naively, both look like "valid": false, which tells the
     * administrator a number is not registered when the register never answered.
     *
     * @return array<string, mixed>
     */
    private function ask(string $countryCode, string $number): array
    {
        try {
            // A fresh client per call: the shared Curl instance carries headers and options from
            // whichever code used it earlier in the request, and would carry ours on to the next.
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT_SECONDS);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->post(self::ENDPOINT, (string)$this->json->serialize([
                'countryCode' => $countryCode,
                'vatNumber' => $number,
            ]));

            if ($curl->getStatus() !== 200) {
                return ['error' => 'VIES answered with HTTP ' . $curl->getStatus()];
            }

            $body = $curl->getBody();
        } catch (\Throwable $e) {
            // The register is regularly unavailable for one member state at a time. An error the
            // model can read beats an exception the administrator never sees.
            return ['error' => 'Could not reach VIES: ' . $e->getMessage()];
        }

        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException) {
            $decoded = null;
        }

        if (!is_array($decoded)) {
            return ['error' => 'VIES returned something unreadable'];
        }

        if (($decoded['actionSucceed'] ?? true) === false || !empty($decoded['errorWrappers'])) {
            return ['error' => 'VIES could not answer: ' . $this->errorCode($decoded['errorWrappers'][0]['error'] ?? null)];
        }

        $userError = $decoded['userError'] ?? null;
        if ($userError !== null && !in_array($userError, ['VALID', 'INVALID'], true)) {
            return ['error' => 'VIES could not answer: ' . $this->errorCode($userError)];
        }

        if (!array_key_exists('valid', $decoded)) {
            return ['error' => 'VIES returned something unreadable'];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function normalise(string $vatNumber): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber) ?? '');
    }

    /**
     * Only a code like MS_UNAVAILABLE crosses to the model, never free text from the response.
     */
    private function errorCode(mixed $code): string
    {
        return is_string($code) && preg_match('/^[A-Z_]{1,40}$/', $code) === 1 ? $code : 'unknown error';
    }

    private function tidy(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)preg_replace('/\s+/', ' ', (string)$value)) : '';

        return $value === self::NOT_SHARED ? '' : $value;
    }
}
