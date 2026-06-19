<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\Enum\MetafieldType;
use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * Metafield definitions — the admin-visible, typed declarations. A metafield set
 * without a definition is API-only; defining it makes it render (and be editable)
 * in the admin. The definition's type must match the metafields you set.
 */
final class MetafieldDefinitionExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Define a product metafield so `custom.care` renders in the admin. The type
     * (here single-line text) must match the metafields you write with that key.
     */
    public function defineForProduct(): array
    {
        return $this->shopify->metafieldDefinitions()->defineProduct(
            'custom',
            'care',
            MetafieldType::SingleLineText,
            'Care instructions',
        );
    }

    /**
     * The ERP order fields, set up once so they show on the order page. This is
     * the idempotent setup pattern: a re-run returns a `TAKEN` userError, which
     * we treat as "already exists".
     *
     * @return list<array<string,mixed>>
     */
    public function ensureErpOrderFields(): array
    {
        $defs = $this->shopify->metafieldDefinitions();
        $results = [];

        $fields = [
            ['order_id', 'single_line_text_field', 'ERP Order ID'],
            ['synced',   'boolean',                'ERP Synced'],
            ['message',  'single_line_text_field', 'ERP Message'],
        ];

        foreach ($fields as [$key, $type, $name]) {
            $result = $defs->defineOrder('erp', $key, $type, $name);
            foreach ($result['userErrors'] as $error) {
                if (($error['code'] ?? '') !== 'TAKEN') {
                    throw new \RuntimeException("erp.{$key}: {$error['message']}");
                }
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Define a customer metafield (`erp.customer_id`). `defineProduct`,
     * `defineOrder` and `defineCustomer` are owner-specific shortcuts for the
     * generic `define($ownerType, …)`.
     */
    public function defineForCustomer(): array
    {
        return $this->shopify->metafieldDefinitions()->defineCustomer('erp', 'customer_id', 'single_line_text_field', 'ERP Customer ID');
    }

    /**
     * A definition with extra options (description + validations).
     */
    public function defineWithValidations(): array
    {
        return $this->shopify->metafieldDefinitions()->defineProduct(
            'erp',
            'priority',
            MetafieldType::NumberInteger,
            'ERP Priority',
            [
                'description' => 'Priority assigned by the ERP',
                'validations' => [['name' => 'min', 'value' => '0'], ['name' => 'max', 'value' => '10']],
            ],
        );
    }
}
