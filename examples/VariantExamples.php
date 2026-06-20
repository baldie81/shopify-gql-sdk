<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\VariantInput;

/**
 * VARIANTS — a product's purchasable combinations (per option), carrying price,
 * compareAtPrice and the inventory-item fields (sku, barcode, tracked, cost).
 * Reached via `$shopify->variants()`.
 *
 *   $ex = new VariantExamples(ExampleClient::create());
 *
 * Real-life usage: a write method takes **one variant/price row** as `$data` (an
 * ERP price feed row, defaulting to {@see SAMPLE}). Product/variant ids stay as
 * params — you'd have them from Shopify. Typical loop:
 *
 *   foreach ($erp->priceRows() as $row) {
 *       $ex->update($row['productId'], $row['variantId'], $row);
 *   }
 *
 * Operations shown in this file:
 *   - list($productId)                       — a product's variants
 *   - update($productId, $variantId, $data)  — productVariantsBulkUpdate from a price row
 *   - create($productId, $data)              — productVariantsBulkCreate (options must exist)
 *   - delete($productId, $variantId)         — productVariantsBulkDelete (keep ≥1 variant)
 *
 * Key points:
 *   - The bulk mutations are **product-scoped** and **auto-chunk at 250** variants.
 *   - This is for *prices* and *variant attributes*. Variant **quantities** live
 *     in InventoryExamples; per-market/B2B prices in PriceListExamples.
 *   - To add a variant, the matching option (e.g. Color=Green) must already exist
 *     — define options with `products()->set()` (see ProductExamples::set()).
 *
 * Related: ProductExamples, InventoryExamples, PriceListExamples.
 */
final class VariantExamples
{
    /** One variant/price row, shaped like an ERP feed row. */
    private const SAMPLE = [
        'sku'            => 'POLO-RED',
        'price'          => '24.90',
        'compareAtPrice' => '29.90',
        'newColor'       => 'Green',
        'newColorPrice'  => '19.90',
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * List a product's variants (id, sku, price, compareAtPrice, inventory item).
     */
    public function list(string $productId): array
    {
        return $this->shopify->variants()->list($productId);
    }

    /**
     * Apply one ERP price row to an existing variant (sale price + tracking).
     *
     * @param array<string,mixed> $data a price row (defaults to {@see SAMPLE})
     */
    public function update(string $productId, string $variantId, array $data = self::SAMPLE): array
    {
        return $this->shopify->variants()->update($productId, [
            VariantInput::make($variantId)
                ->price($data['price'])
                ->compareAtPrice($data['compareAtPrice'])
                ->tracked(true),
        ]);
    }

    /**
     * Add a new variant (from the record) to a product whose options exist.
     *
     * @param array<string,mixed> $data a record with the new option value + price
     */
    public function create(string $productId, array $data = self::SAMPLE): array
    {
        return $this->shopify->variants()->create($productId, [
            [
                'optionValues'  => [['optionName' => 'Color', 'name' => $data['newColor']]],
                'price'         => $data['newColorPrice'],
                'inventoryItem' => ['sku' => 'POLO-' . strtoupper($data['newColor']), 'tracked' => true],
            ],
        ]);
    }

    /**
     * Delete one or more variants from a product (bulk delete). Pass a list of
     * variant GIDs; a product must always keep at least one variant.
     */
    public function delete(string $productId, string $variantId): array
    {
        return $this->shopify->variants()->delete($productId, [$variantId]);
    }
}
