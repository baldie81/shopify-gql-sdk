<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\Enum\ProductStatus;
use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use Baldie81\ShopifyGraphQL\Support\ProductInput;

/**
 * How to work with products: create, update, productSet, delete, read/search,
 * metafields and finding a product by SKU.
 *
 * Every write returns `['<root> => ..., 'userErrors' => [...]]` — always check
 * `userErrors` (validation problems come back there, not as exceptions).
 */
final class ProductExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Create a product with a couple of fields and an inline metafield.
     */
    public function create(): array
    {
        $input = ProductInput::make('SDK Example Tee')
            ->descriptionHtml('<p>Created via the SDK.</p>')
            ->vendor('Acme')
            ->productType('Shirts')
            ->tags(['example', 'sdk'])
            ->status(ProductStatus::Active)
            ->metafield(Metafield::text('custom', 'care', 'Machine wash cold'));

        $result = $this->shopify->products()->create($input);
        // $result['product']['id'], $result['product']['legacyResourceId'], $result['userErrors']
        return $result;
    }

    /**
     * Partial update — only the fields you set are sent.
     */
    public function update(string $productId): array
    {
        return $this->shopify->products()->update(
            $productId,
            ProductInput::make()->title('SDK Example Tee (v2)')->status(ProductStatus::Draft),
        );
    }

    /**
     * `productSet` — declare options and variants in one idempotent call. Useful
     * for importing a product whose full shape you already know.
     */
    public function set(): array
    {
        return $this->shopify->products()->set([
            'title'         => 'SDK Example Polo',
            'status'        => 'ACTIVE',
            'productOptions' => [
                ['name' => 'Color', 'values' => [['name' => 'Red'], ['name' => 'Blue']]],
            ],
            'variants' => [
                ['optionValues' => [['optionName' => 'Color', 'name' => 'Red']], 'price' => '19.90', 'sku' => 'POLO-RED'],
                ['optionValues' => [['optionName' => 'Color', 'name' => 'Blue']], 'price' => '19.90', 'sku' => 'POLO-BLUE'],
            ],
        ]);
    }

    /**
     * Permanently delete a product (`productDelete`). The result carries the
     * deleted product's id in `deletedProductId`.
     */
    public function delete(string $productId): array
    {
        return $this->shopify->products()->delete($productId);
    }

    /**
     * Read one product (accepts a numeric id or a GID).
     */
    public function get(string $productId): ?array
    {
        return $this->shopify->products()->get($productId);
    }

    /**
     * One page of a search (Shopify query syntax), with the cursor for the next page.
     */
    public function searchPage(): array
    {
        $page = $this->shopify->products()->search('status:active', first: 25);
        // $page['nodes'], $page['pageInfo']['hasNextPage'], $page['pageInfo']['endCursor']
        return $page;
    }

    /**
     * Walk every page and return all matching products (cursor pagination handled).
     */
    public function searchAll(): array
    {
        return $this->shopify->products()->searchAll('tag:sdk');
    }

    /**
     * Upsert metafields (create-or-update by namespace+key), auto-chunked at 25.
     */
    public function setMetafields(string $productId): array
    {
        return $this->shopify->products()->setMetafields($productId, [
            Metafield::text('custom', 'care', 'Hand wash'),
            Metafield::boolean('custom', 'featured', true),
            Metafield::integer('custom', 'warranty_months', 24),
            Metafield::json('custom', 'specs', ['weight' => '180g', 'fit' => 'regular']),
        ]);
    }

    /**
     * Resolve a product GID from a variant SKU (null if not found).
     */
    public function findBySku(string $sku): ?string
    {
        return $this->shopify->products()->findIdBySku($sku);
    }
}
