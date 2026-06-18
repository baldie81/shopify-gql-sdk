# Shopify Admin GraphQL PHP SDK — *spike*

A small, **framework-independent** PHP client for the Shopify **Admin GraphQL**
API. No framework coupling — just PHP 8.4+ and Guzzle. Shopify is deprecating the
REST Admin API, so this is GraphQL-only from the start.

> **Status: spike.** This is a deliberately narrow vertical slice — **Products**
> only (get / search / searchAll / create / update) — built to validate the
> conventions (transport, selection sets, cursor pagination, the three error
> layers, GIDs, throttling) before widening the surface. It mirrors the patterns
> of the companion `baldie81/magento2-sdk` package, adapted for GraphQL.

## What's here

- **`ShopifyClient`** — entry point, lazy resource sub-clients (`products()`).
- **`GraphQL\Client`** — single-endpoint POST transport: encodes `{query, variables}`,
  decodes `{data, errors, extensions}`, normalises errors, and **backs off + retries
  on `THROTTLED`** using Shopify's calculated query-cost block.
- **`Resource\Products`** — queries/mutations with a lean default selection set.
- **`Support\ProductInput`** — fluent `Arrayable` builder for `ProductCreateInput` /
  `ProductUpdateInput` (write methods accept `builder|array`).
- **`Support\Gid`** — global-ID helpers (`gid://shopify/Product/123`).
- **`Enum\ProductStatus`** — `ACTIVE` / `ARCHIVED` / `DRAFT`.
- **Exceptions** — `GraphQLException` (top-level errors / non-2xx), `ThrottledException`
  (cost-limit / 429), `TransportException`, under a `ShopifyException` base.

## Quick start

```php
use Baldie81\ShopifyGraphQL\Configuration;
use Baldie81\ShopifyGraphQL\ShopifyClient;

$shopify = ShopifyClient::create(new Configuration(
    shop:        'my-store',          // or 'my-store.myshopify.com'
    accessToken: 'shpat_...',         // Admin API access token
    apiVersion:  '2025-07',           // dated, quarterly; ~12-month support window
));

$product = $shopify->products()->get('123');   // numeric id or a GID
```

## Products

```php
use Baldie81\ShopifyGraphQL\Support\ProductInput;
use Baldie81\ShopifyGraphQL\Enum\ProductStatus;

// Read — one page (Relay connection: nodes + pageInfo):
$page = $shopify->products()->search('status:active vendor:Acme', first: 50);

// Read — every page, cursor-walked and merged:
$all = $shopify->products()->searchAll('status:active');

// Create — returns { product, userErrors }; check userErrors for validation:
$res = $shopify->products()->create(
    ProductInput::make('Summer Hat')
        ->descriptionHtml('<p>Straw hat</p>')
        ->vendor('Acme')->productType('Hat')->status(ProductStatus::Draft)
        ->tags(['summer', 'sale'])
);
if ($res['userErrors'] !== []) { /* … */ }

// Update — id (numeric or GID) is injected into the input:
$shopify->products()->update($res['product']['id'], ProductInput::make()->status(ProductStatus::Active));
```

## GraphQL specifics (how this differs from a REST SDK)

- **Selection sets.** Reads return a curated default field set (`Products::FIELDS`),
  kept lean because Shopify bills by query cost. (Caller-supplied selections are a
  planned extension.)
- **Cursor pagination.** No page numbers — connections expose `pageInfo
  { hasNextPage, endCursor }`; `searchAll()` walks `after` until exhausted (page
  size capped at Shopify's max of 250).
- **Three error layers.** (1) transport (`TransportException`), (2) top-level
  `errors[]` / non-2xx (`GraphQLException`, incl. `ThrottledException`), and
  (3) **`userErrors`** inside mutation payloads — business validation returned as
  **data, not thrown**, so you inspect `$result['userErrors']`.
- **Throttling.** The client detects `extensions.code = THROTTLED` (and HTTP 429),
  computes a backoff from `extensions.cost`, and retries (default 2 attempts).
- **GIDs.** Everything is identified by `gid://shopify/<Type>/<id>`; helpers coerce
  numeric ids and extract them back.
- **Versioning.** The dated `apiVersion` is part of the endpoint and is required.

## Tests

```bash
composer install
composer test
```

Unit tests mock Guzzle (no network). The live check is `examples/products_smoke_test.php`
(read-only unless `SHOPIFY_SMOKE_WRITE=1`); it needs `SHOPIFY_SHOP` + `SHOPIFY_TOKEN`.

## Not yet in the spike

Other resources (orders, customers, inventory…), caller-supplied selection sets,
the Bulk Operations API (async JSONL), `productDelete`, and variants
(`productVariantsBulkCreate`). The Products slice is the template the rest follows.

## License

MIT.
