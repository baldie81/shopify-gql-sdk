<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\VariantInput;

/**
 * Variants — prices and the inventory-item fields (sku, barcode, tracked, cost).
 * The bulk mutations are product-scoped and auto-chunk at 250.
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
