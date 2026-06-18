<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\Gid;
use Baldie81\ShopifyGraphQL\Support\ProductInput;

/**
 * Products: the `product`/`products` queries and the `productCreate` /
 * `productUpdate` mutations.
 *
 * Reads use a default selection set ({@see FIELDS}); mutations return the
 * `{ product, userErrors }` payload unchanged so callers can inspect
 * `userErrors` (Shopify's business-validation results, returned as data rather
 * than thrown).
 */
final class Products extends AbstractResource
{
    /**
     * The default fields fetched for a product. Kept lean on purpose — Shopify
     * bills by query cost, so over-fetching has a real price.
     */
    private const FIELDS = <<<'GQL'
        id
        title
        handle
        status
        vendor
        productType
        tags
        createdAt
        updatedAt
        GQL;

    /**
     * Fetch a single product by id (numeric id or a GID). Returns the product
     * node, or null if it doesn't exist.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        $query = 'query ($id: ID!) { product(id: $id) { ' . self::FIELDS . ' } }';
        $data  = $this->gql->execute($query, ['id' => Gid::product($id)]);

        return $data['product'] ?? null;
    }

    /**
     * Run a product search for a single page, returning the Relay connection
     * (`nodes` + `pageInfo`). `$query` is Shopify's search syntax, e.g.
     * `status:active vendor:Acme`. Use {@see searchAll()} to walk every page.
     *
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function search(string $query = '', int $first = 50, ?string $after = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery(), [
            'first' => $first,
            'after' => $after,
            'query' => $query,
        ]);

        return $data['products'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * Like {@see search()} but follows the cursor through every page and returns
     * the merged product nodes.
     *
     * @return list<array<string,mixed>>
     */
    public function searchAll(string $query = '', int $pageSize = self::MAX_PAGE_SIZE): array
    {
        return $this->paginateNodes(self::listQuery(), ['query' => $query], 'products', $pageSize);
    }

    /**
     * Create a product. Accepts a {@see ProductInput} builder or a raw array
     * (the inner `ProductCreateInput`). Returns the `{ product, userErrors }`
     * payload — check `userErrors` for business-validation failures.
     *
     * @param ProductInput|array<string,mixed> $input
     * @return array{product:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function create(ProductInput|array $input): array
    {
        $mutation = 'mutation ($product: ProductCreateInput!) {'
            . ' productCreate(product: $product) {'
            . ' product { ' . self::FIELDS . ' } userErrors { field message } } }';

        $data = $this->gql->execute($mutation, ['product' => $this->payload($input)]);

        return $data['productCreate'] ?? ['product' => null, 'userErrors' => []];
    }

    /**
     * Update a product by id (numeric id or GID). The id is injected into the
     * `ProductUpdateInput`, so the builder/array carries only the changed fields.
     *
     * @param ProductInput|array<string,mixed> $input
     * @return array{product:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function update(string $id, ProductInput|array $input): array
    {
        $mutation = 'mutation ($product: ProductUpdateInput!) {'
            . ' productUpdate(product: $product) {'
            . ' product { ' . self::FIELDS . ' } userErrors { field message } } }';

        $product = ['id' => Gid::product($id)] + $this->payload($input);
        $data    = $this->gql->execute($mutation, ['product' => $product]);

        return $data['productUpdate'] ?? ['product' => null, 'userErrors' => []];
    }

    private static function listQuery(): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' products(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . self::FIELDS . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
