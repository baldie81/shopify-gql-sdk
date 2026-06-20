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
 * Operations shown in this file:
 *   - list()    — a product's variants (id, sku, price, compareAtPrice, inventory item)
 *   - update()  — productVariantsBulkUpdate via the VariantInput builder
 *   - create()  — productVariantsBulkCreate (the product's options must already exist)
 *   - delete()  — productVariantsBulkDelete (a product must keep ≥1 variant)
 *
 * Key points:
 *   - The bulk mutations are **product-scoped** (you pass the productId) and the
 *     SDK **auto-chunks at 250** variants per call, merging the results.
 *   - This is for *prices* and *variant attributes*. Variant **quantities** live
 *     in InventoryExamples; per-market/B2B prices live in PriceListExamples.
 *   - To add a variant, the matching option (e.g. Color=Green) must exist — define
 *     options up front with `products()->set()` (see ProductExamples::set()).
 *   - `VariantInput` formats money for you; `tracked(true)` is required before a
 *     quantity will stick (then stock it via InventoryExamples).
 *
 * Related: ProductExamples (options/`set`), InventoryExamples (stock),
 * PriceListExamples (contextual pricing).
 */
final class VariantExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * List a product's variants (id, sku, price, compareAtPrice, inventory item).
     */
    public function list(string $productId): array
    {
        return $this->shopify->variants()->list($productId);
    }

    /**
     * Update existing variants — set a sale price and mark them tracked.
     */
    public function update(string $productId, string $variantId): array
    {
        return $this->shopify->variants()->update($productId, [
            VariantInput::make($variantId)
                ->price(24.90)
                ->compareAtPrice(29.90)
                ->tracked(true),
        ]);
    }

    /**
     * Add a new variant to a product whose options already exist.
     */
    public function create(string $productId): array
    {
        return $this->shopify->variants()->create($productId, [
            [
                'optionValues' => [['optionName' => 'Color', 'name' => 'Green']],
                'price'        => '19.90',
                'inventoryItem' => ['sku' => 'POLO-GREEN', 'tracked' => true],
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
