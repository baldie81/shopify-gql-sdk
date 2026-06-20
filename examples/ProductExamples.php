<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\Enum\ProductStatus;
use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use Baldie81\ShopifyGraphQL\Support\ProductInput;

/**
 * PRODUCTS — the core catalog object: the sellable item plus its options, media,
 * SEO, status and metafields. Reached via `$shopify->products()`.
 *
 *   $shopify = ExampleClient::create();      // edit the dummy creds first
 *   $ex      = new ProductExamples($shopify);
 *
 * Real-life usage: a write method takes **one product record** as `$data`
 * (mock-shaped here like an ERP payload, defaulting to {@see SAMPLE} so it runs
 * as-is). In a real importer you'd loop your feed and pass one record per call:
 *
 *   foreach ($erp->products() as $record) {
 *       $ex->create($record);
 *   }
 *
 * Shopify object ids (productId) stay as separate params — you'd have those from
 * Shopify or your ERP↔Shopify id map.
 *
 * Operations shown in this file:
 *   - create($data)             — productCreate from an ERP record (+ metafield)
 *   - update($productId, $data) — productUpdate; partial, only fields you set
 *   - set($data)                — productSet: options + variants from the record
 *   - delete($productId)        — productDelete (returns deletedProductId)
 *   - get($productId)           — read one product (numeric id or GID)
 *   - searchPage()              — one page + the cursor for the next
 *   - searchAll()               — every page, cursor pagination handled
 *   - setMetafields($productId, $data) — upsert the record's custom attributes
 *   - findBySku($data)          — resolve a product GID from the ERP SKU
 *
 * Conventions used across the SDK:
 *   - IDs accept a numeric id OR a full `gid://shopify/Product/123`; the SDK coerces.
 *   - Writes return `['<root> => ..., 'userErrors' => [...]]`. Validation problems
 *     come back in `userErrors` (NOT as exceptions); only transport / throttling /
 *     non-2xx HTTP throw (TransportException / ThrottledException / GraphQLException).
 *   - Builders (`ProductInput`, `Metafield`, …) are `Arrayable`; write methods
 *     accept either a builder or a plain array.
 *
 * Related: variants & prices → VariantExamples; stock → InventoryExamples;
 * localisation → TranslationExamples; grouping → CollectionExamples; making
 * metafields show in the admin → MetafieldDefinitionExamples; mass updates →
 * BulkOperationExamples.
 */
final class ProductExamples
{
    /** One product record, shaped like an ERP payload. */
    private const SAMPLE = [
        'sku'             => 'TEE-001',
        'title'           => 'Organic Cotton Tee',
        'descriptionHtml' => '<p>Soft, breathable organic cotton.</p>',
        'vendor'          => 'Acme',
        'type'            => 'Shirts',
        'tags'            => ['organic', 'tee'],
        'status'          => 'ACTIVE',
        'price'           => '19.90',
        'options'         => ['Color' => ['Red', 'Blue']],
        'metafields'      => [
            'care'            => 'Machine wash cold',
            'featured'        => true,
            'warranty_months' => 24,
            'specs'           => ['weight' => '180g', 'fit' => 'regular'],
        ],
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Create a product by mapping one ERP record onto a ProductInput builder.
     *
     * @param array<string,mixed> $data one product record (defaults to {@see SAMPLE})
     */
    public function create(array $data = self::SAMPLE): array
    {
        $input = ProductInput::make($data['title'])
            ->descriptionHtml($data['descriptionHtml'])
            ->vendor($data['vendor'])
            ->productType($data['type'])
            ->tags($data['tags'])
            ->status($data['status'])
            ->metafield(Metafield::text('custom', 'care', $data['metafields']['care']));

        return $this->shopify->products()->create($input);
    }

    /**
     * Partial update — only the fields you set are sent. `$productId` comes from
     * Shopify (e.g. looked up from your ERP↔Shopify id map).
     *
     * @param array<string,mixed> $data the record carrying the new values
     */
    public function update(string $productId, array $data = self::SAMPLE): array
    {
        return $this->shopify->products()->update(
            $productId,
            ProductInput::make()->title($data['title'])->status(ProductStatus::Draft),
        );
    }

    /**
     * `productSet` — declare options and variants in one idempotent call, built
     * from the record's option matrix (e.g. Color => [Red, Blue]).
     *
     * @param array<string,mixed> $data one product record
     */
    public function set(array $data = self::SAMPLE): array
    {
        $optionName = array_key_first($data['options']); // 'Color'
        $values     = $data['options'][$optionName];     // ['Red', 'Blue']

        return $this->shopify->products()->set([
            'title'          => $data['title'],
            'status'         => $data['status'],
            'productOptions' => [
                ['name' => $optionName, 'values' => array_map(static fn (string $v): array => ['name' => $v], $values)],
            ],
            'variants' => array_map(static fn (string $v): array => [
                'optionValues' => [['optionName' => $optionName, 'name' => $v]],
                'price'        => $data['price'],
                'sku'          => $data['sku'] . '-' . strtoupper($v),
            ], $values),
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
        return $this->shopify->products()->searchAll('tag:organic');
    }

    /**
     * Upsert one record's custom attributes as metafields (chunked at 25).
     *
     * @param array<string,mixed> $data one product record (uses its `metafields`)
     */
    public function setMetafields(string $productId, array $data = self::SAMPLE): array
    {
        $mf = $data['metafields'];

        return $this->shopify->products()->setMetafields($productId, [
            Metafield::text('custom', 'care', $mf['care']),
            Metafield::boolean('custom', 'featured', $mf['featured']),
            Metafield::integer('custom', 'warranty_months', $mf['warranty_months']),
            Metafield::json('custom', 'specs', $mf['specs']),
        ]);
    }

    /**
     * Resolve a product GID from the record's SKU (null if not found) — the usual
     * way to map an ERP record to its Shopify product.
     *
     * @param array<string,mixed> $data one product record (uses its `sku`)
     */
    public function findBySku(array $data = self::SAMPLE): ?string
    {
        return $this->shopify->products()->findIdBySku($data['sku']);
    }
}
