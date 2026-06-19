<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\Gid;
use Baldie81\ShopifyGraphQL\Support\VariantInput;

/**
 * Product variants — prices (`price`, `compareAtPrice`) and the variant-level
 * `inventoryItem` fields (sku, barcode, tracked, cost), via
 * `productVariantsBulkUpdate`. Variant *quantities* live in {@see Inventory}.
 */
final class Variants extends AbstractResource
{
    private const FIELDS = 'id title sku price compareAtPrice inventoryQuantity inventoryItem { id tracked }';

    /**
     * List a product's variants (id, sku, price, compareAtPrice, inventory item).
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $productId, int $first = self::MAX_PAGE_SIZE): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $query = 'query ($id: ID!, $first: Int!) {'
            . ' product(id: $id) { variants(first: $first) { nodes { ' . self::FIELDS . ' } } } }';

        return $this->gql->execute($query, ['id' => Gid::product($productId), 'first' => $first])['product']['variants']['nodes'] ?? [];
    }

    /**
     * Update variants in bulk (prices/sku/tracked/…). Accepts {@see VariantInput}
     * builders or raw arrays (each needs `id`).
     *
     * @param list<VariantInput|array<string,mixed>> $variants
     * @return array{productVariants:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function update(string $productId, array $variants): array
    {
        $mutation = 'mutation ($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {'
            . ' productVariantsBulkUpdate(productId: $productId, variants: $variants) {'
            . ' productVariants { ' . self::FIELDS . ' } userErrors { field message } } }';

        $data = $this->gql->execute($mutation, [
            'productId' => Gid::product($productId),
            'variants'  => $this->payloads($variants),
        ]);

        return $data['productVariantsBulkUpdate'] ?? ['productVariants' => [], 'userErrors' => []];
    }

    /**
     * Add variants to a product in bulk (`productVariantsBulkCreate`). The product
     * must already have the matching options defined (see {@see \Baldie81\ShopifyGraphQL\Resource\Products::set()}).
     *
     * @param list<VariantInput|array<string,mixed>> $variants
     * @return array{productVariants:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function create(string $productId, array $variants): array
    {
        $mutation = 'mutation ($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {'
            . ' productVariantsBulkCreate(productId: $productId, variants: $variants) {'
            . ' productVariants { ' . self::FIELDS . ' } userErrors { field message } } }';

        $data = $this->gql->execute($mutation, [
            'productId' => Gid::product($productId),
            'variants'  => $this->payloads($variants),
        ]);

        return $data['productVariantsBulkCreate'] ?? ['productVariants' => [], 'userErrors' => []];
    }

    /**
     * Delete variants from a product (`productVariantsBulkDelete`).
     *
     * @param list<string> $variantIds
     * @return array{product:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function delete(string $productId, array $variantIds): array
    {
        $mutation = 'mutation ($productId: ID!, $variantsIds: [ID!]!) {'
            . ' productVariantsBulkDelete(productId: $productId, variantsIds: $variantsIds) {'
            . ' product { id } userErrors { field message } } }';

        $data = $this->gql->execute($mutation, [
            'productId'   => Gid::product($productId),
            'variantsIds' => array_values($variantIds),
        ]);

        return $data['productVariantsBulkDelete'] ?? ['product' => null, 'userErrors' => []];
    }
}
