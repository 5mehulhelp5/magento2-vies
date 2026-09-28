<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Vies\Service\Tool;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
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

    public function __construct(
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
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
        return 'Validate an EU VAT number against VIES, the European Commission register. '
            . 'Either pass a vat_number directly, or pass an order_number to check the VAT number on '
            . 'that order\'s billing address. Answers whether the number is registered and, if the '
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
                    'description' => 'VAT number including its country prefix, e.g. "NL810433941B01". '
                        . 'Leave out when passing an order_number instead.',
                ],
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Order increment id, e.g. "000000563". The VAT number is read '
                        . 'from that order\'s billing address. Leave out when passing a vat_number.',
                ],
            ],
        ];
    }

    /**
     * Reading an order needs the same permission the admin would need to view it. An empty input has
     * to resolve to the most restrictive resource the tool can reach: the check runs before the
     * arguments are known to be harmless.
     *
     * @param array<string,mixed> $input
     */
    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Sales::actions_view';
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
     * the model is handed mago://name_1 and the panel shows the administrator the real value.
     */
    public function getFieldClassification(string $action = ''): array
    {
        return [
            'valid' => [PiiClass::PUBLIC],
            'vat_number' => [PiiClass::PUBLIC],
            'country_code' => [PiiClass::PUBLIC],
            'request_date' => [PiiClass::PUBLIC],
            'order_number' => [PiiClass::PUBLIC],
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
        return 'An invalid VAT number is not proof of fraud: a member state can be temporarily '
            . 'unreachable, and some states do not return a name or address at all. Say what the '
            . 'register answered rather than what it implies.';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $orderNumber = trim((string)($params['order_number'] ?? ''));
        $vatNumber = trim((string)($params['vat_number'] ?? ''));

        if ($vatNumber === '' && $orderNumber !== '') {
            $vatNumber = $this->vatNumberOnOrder($orderNumber);
            if ($vatNumber === '') {
                return ['error' => 'Order ' . $orderNumber . ' has no VAT number on its billing address'];
            }
        }

        $vatNumber = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber) ?? '');
        if (strlen($vatNumber) < 3) {
            return ['error' => 'vies_vat_check needs either a vat_number or an order_number'];
        }

        $result = $this->ask(substr($vatNumber, 0, 2), substr($vatNumber, 2));
        if (isset($result['error'])) {
            return $result;
        }

        return [
            'valid' => (bool)($result['valid'] ?? false),
            'vat_number' => $vatNumber,
            'country_code' => (string)($result['countryCode'] ?? ''),
            'request_date' => (string)($result['requestDate'] ?? ''),
            'order_number' => $orderNumber !== '' ? $orderNumber : null,
            'name' => $this->tidy((string)($result['name'] ?? '')),
            'address' => $this->tidy((string)($result['address'] ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(string $countryCode, string $number): array
    {
        try {
            $this->curl->setTimeout(15);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->post(self::ENDPOINT, (string)$this->json->serialize([
                'countryCode' => $countryCode,
                'vatNumber' => $number,
            ]));

            if ($this->curl->getStatus() !== 200) {
                return ['error' => 'VIES answered with HTTP ' . $this->curl->getStatus()];
            }

            $decoded = $this->json->unserialize($this->curl->getBody());

            return is_array($decoded) ? $decoded : ['error' => 'VIES returned something unreadable'];
        } catch (\Throwable $e) {
            // The register is regularly unavailable for one member state at a time. An error the
            // model can read beats an exception the administrator never sees.
            return ['error' => 'Could not reach VIES: ' . $e->getMessage()];
        }
    }

    private function vatNumberOnOrder(string $incrementId): string
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
            $address = $order->getBillingAddress();

            return $address !== null ? (string)$address->getVatId() : '';
        }

        return '';
    }

    private function tidy(string $value): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $value));
    }
}
