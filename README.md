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
- **`Resource\Products`** — queries/mutations with a lean default selection set,
  plus metafields (`setMetafields`) and idempotent media (`ensureMedia`).
- **`Resource\BulkOperations`** — run a bulk query/mutation, poll, read the JSONL.
- **`Resource\Translations`** — translate any resource (products, metafields…),
  with the digest lookup handled for you.
- **`Support\ProductInput`** — fluent `Arrayable` builder for `ProductCreateInput` /
  `ProductUpdateInput`, incl. inline metafields (write methods accept `builder|array`).
- **`Support\Metafield`** — builder for metafields of any type (serialises the value).
- **`Support\Media`** — `CreateMediaInput` builder whose `alt` is the idempotency key.
- **`Support\Translation`** — `TranslationInput` builder (digest optional).
- **`Support\Gid`** — global-ID helpers (`gid://shopify/Product/123`).
- **Enums** — `ProductStatus`, `MetafieldType`, `MediaContentType`,
  `BulkOperationStatus`, `ProductTranslatableField`.
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

## Metafields (all types)

Shopify stores every metafield value as a string; the `Metafield` builder
serialises by PHP type (bools → `true`/`false`, scalars stringified, arrays/objects
JSON-encoded — covering `json`, `list.*`, and structured types like `dimension`/
`weight`/`rating`/`money`). `metafieldsSet` upserts by namespace+key, so this is
idempotent by construction.

```php
use Baldie81\ShopifyGraphQL\Support\Metafield;
use Baldie81\ShopifyGraphQL\Enum\MetafieldType;

$shopify->products()->setMetafields($productId, [
    Metafield::text('specs', 'material', 'Wool'),
    Metafield::integer('specs', 'capacity', 25),
    Metafield::boolean('specs', 'waterproof', true),
    Metafield::json('specs', 'matrix', ['rows' => 3, 'cols' => 4]),
    Metafield::make('specs', 'volume', MetafieldType::Volume, ['value' => 1.5, 'unit' => 'LITERS']),
    Metafield::reference('specs', 'related', 'gid://shopify/Product/9'),
    Metafield::make('specs', 'anything', 'a_brand_new_type', 'raw value'), // raw type string
]);
// returns { metafields, userErrors }; chunked to Shopify's 25-per-call limit.
```

Metafields can also ride along with a create/update via `ProductInput::metafield()`.

## Media (idempotent / upsert)

`productCreateMedia` does **not** dedupe by source, so calling it twice creates
duplicate files. `ensureMedia()` fixes that: it compares each media's **key (its
`alt`)** against the product's existing media and creates only what's missing — so
re-runs never add the same asset twice. Give each asset a stable `alt` (it defaults
to the source filename).

```php
use Baldie81\ShopifyGraphQL\Support\Media;

$result = $shopify->products()->ensureMedia($productId, [
    Media::image('https://cdn.example.com/front.jpg', alt: 'front'),
    Media::image('https://cdn.example.com/back.jpg',  alt: 'back'),
]);
// $result === ['added' => [...], 'skipped' => ['front'], 'mediaUserErrors' => []]

// Run it again with the same input → everything is skipped, nothing duplicated.
$shopify->products()->addMedia($productId, [...]);  // raw, non-idempotent escape hatch
```

> Idempotency is keyed on `alt` because that's the only writable, queryable string
> on a media object — so reserve `alt` as a stable key per asset.

## Bulk operations

Run a query (export) or mutation (import) that Shopify processes asynchronously and
returns as a JSONL file. Kick off, poll to a terminal status, then read `url`.

```php
$bulk = $shopify->bulkOperations();

$op = $bulk->runQuery('{ products { edges { node { id title } } } }')['bulkOperation'];
$final = $bulk->poll($op['id']);          // waits until COMPLETED/FAILED/CANCELED/EXPIRED

if (($final['status'] ?? null) === 'COMPLETED') {
    $jsonlUrl = $final['url'];             // download + parse line-by-line
}

// Imports run a mutation per JSONL line; upload variables via staged uploads first,
// then pass the staged path:
$bulk->runMutation($mutationString, $stagedUploadPath);
$bulk->current('QUERY');                  // most recent op of a type
$bulk->cancel($op['id']);
```

## Translations

Every registered translation must carry the `translatableContentDigest` of the
source content, so the flow is always *read digests, then register*. `set()` does
both — you just pass a locale and a map of values.

The translatable **product** fields are `title`, **`body_html` (the description)**,
`handle`, and `product_type`, plus the SEO `meta_title` / `meta_description` when
customised. Use them via `ProductTranslatableField` (values are Shopify's keys):

```php
use Baldie81\ShopifyGraphQL\Enum\ProductTranslatableField as F;

$shopify->products()->setTranslations($productId, 'fr', [
    F::Title->value       => 'Chapeau de paille',
    F::Description->value => '<p>Un chapeau de paille</p>',   // body_html
    F::Handle->value      => 'chapeau-de-paille',
    F::ProductType->value => 'Chapeau',
]);
// returns { translations, userErrors, skipped }; non-translatable keys land in `skipped`.
```

Metafields are translatable too (when publicly accessible) — they're just another
resource, addressed by the **metafield's** GID with the key `value`:

```php
$metafieldGid = $result['metafields'][0]['id'];   // from setMetafields()
$shopify->translations()->set($metafieldGid, 'fr', ['value' => 'Laine']);
```

The general resource works on any translatable GID, exposes the raw content, and
can scope to a market:

```php
$shopify->translations()->translatableContent($gid);          // keys + digests
$shopify->translations()->register($gid, [
    Translation::make('es', ProductTranslatableField::Title, 'Sombrero'),  // digest auto-filled
]);
$shopify->translations()->remove($gid, [ProductTranslatableField::Title], ['fr']);
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
staged uploads (`stagedUploadsCreate`, needed to drive a bulk *mutation*'s JSONL
end-to-end), `productDelete`, and variants (`productVariantsBulkCreate`). The
Products slice is the template the rest follows.

## License

MIT.
