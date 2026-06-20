<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\Enum\MetafieldType;
use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * METAFIELD DEFINITIONS — the admin-visible, typed declarations behind metafields.
 * Reached via `$shopify->metafieldDefinitions()`.
 *
 *   $ex = new MetafieldDefinitionExamples(ExampleClient::create());
 *
 * Why they matter: a metafield set without a definition is **API-only** — it
 * won't render in the admin. Define it (per owner type) and it shows up (and
 * becomes editable) on that resource's admin page.
 *
 * Real-life usage: definitions are part of your integration's one-time setup. The
 * field specs are passed as `$data` (defaulting to {@see SAMPLE}), so you can
 * drive them from config:
 *
 *   foreach ($config['orderMetafields'] as $spec) {
 *       $ex->ensureErpOrderFields([$spec]);
 *   }
 *
 * Operations shown in this file:
 *   - defineForProduct()        — defineProduct (single-line text)
 *   - ensureErpOrderFields($specs) — idempotent ORDER setup (ignores TAKEN)
 *   - defineForCustomer()       — defineCustomer
 *   - defineWithValidations()   — extra options (description + validations)
 *
 * Notes:
 *   - Owner shortcuts `defineProduct` / `defineOrder` / `defineCustomer` wrap the
 *     generic `define($ownerType, …)`; full control via `create($input)`.
 *   - The definition's **type must match** the metafields you write with that key.
 *   - Re-creating an existing definition returns a `TAKEN` userError (no throw) —
 *     treat it as "already exists" for idempotent setup.
 *
 * Related: ProductExamples / OrderExamples / CustomerExamples (which set the values).
 */
final class MetafieldDefinitionExamples
{
    /** ORDER metafield specs (key, type, name) — typically from your config. */
    private const SAMPLE = [
        ['order_id', 'single_line_text_field', 'ERP Order ID'],
        ['synced',   'boolean',                'ERP Synced'],
        ['message',  'single_line_text_field', 'ERP Message'],
    ];

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
     * Define the ERP order fields so they show on the order page. Idempotent: a
     * re-run returns a `TAKEN` userError, which we treat as "already exists".
     *
     * @param list<array{0:string,1:string,2:string}> $specs (key, type, name) tuples
     * @return list<array<string,mixed>>
     */
    public function ensureErpOrderFields(array $specs = self::SAMPLE): array
    {
        $defs    = $this->shopify->metafieldDefinitions();
        $results = [];

        foreach ($specs as [$key, $type, $name]) {
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
