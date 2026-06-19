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
  plus metafields (`setMetafields`), idempotent media (`ensureMedia`), `set()`
  (productSet — options + variants in one call) and `delete()`.
- **`Resource\Variants`** — list / create / update / delete variants
  (`price`, `compareAtPrice`, sku, tracked) via the bulk variant mutations.
- **`Resource\Inventory`** — locations, and set/adjust/activate quantities.
- **`Resource\BulkOperations`** — bulk query, and `importMutation()` (JSONL +
  `BULK_MUTATION_VARIABLES` staged upload), poll, read the JSONL.
- **`Resource\Translations`** — translate any resource (products, metafields…),
  with the digest lookup handled for you.
- **`Resource\Files`** — staged uploads (push local files to Shopify) and
  `fileUpdate` (replace an image's bytes in place).
- **`Resource\MetafieldDefinitions`** — create metafield *definitions*
  (the admin-visible, structured declarations) via `metafieldDefinitionCreate`.
- **`Support\ProductInput`** — fluent `Arrayable` builder for `ProductCreateInput` /
  `ProductUpdateInput`, incl. inline metafields (write methods accept `builder|array`).
- **`Support\VariantInput`** — fluent builder for variant updates (price / compareAtPrice / sku / tracked).
- **`Support\Metafield`** — builder for metafields of any type (serialises the value).
- **`Support\Media`** — `CreateMediaInput` builder whose `alt` is the idempotency key.
- **`Support\MediaEntry` / `Support\MediaFolder`** — positioned media + parsing a
  folder of `SKU_1.jpg`, `SKU_2.jpg`, … drops.
- **`Support\Translation`** — `TranslationInput` builder (digest optional).
- **`Support\ProductField`** — fluent per-field translations (`title()->withTranslation(...)`).
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

Note that `metafieldsSet` writes **values**, not **definitions** — the structured,
admin-visible declaration of a metafield. Create those once (store-wide) with
`metafieldDefinitions()`:

```php
use Baldie81\ShopifyGraphQL\Enum\MetafieldType;

$shopify->metafieldDefinitions()->defineProduct('specs', 'material', MetafieldType::SingleLineText, 'Material');
// some types need validations, e.g. rating:
$shopify->metafieldDefinitions()->defineProduct('specs', 'rating', MetafieldType::Rating, 'Rating', [
    'validations' => [['name' => 'scale_min', 'value' => '1'], ['name' => 'scale_max', 'value' => '5']],
]);
// re-creating an existing definition returns a TAKEN userError (not an exception).
```

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

### Local files & the `SKU_1.jpg` / `SKU_2.jpg` workflow

When images arrive as files (e.g. an FTP drop) rather than URLs, they have no
source Shopify can fetch, so the SDK uploads the bytes via a staged upload first.
A common convention is a folder of `<sku>_<position>.<ext>` files where the
trailing number is the display order (1 = main image). `MediaFolder::scan()` parses
that into ordered slots per SKU, and `Products::syncMedia()` applies them
**idempotently**:

```php
use Baldie81\ShopifyGraphQL\Support\MediaFolder;

foreach (MediaFolder::scan('/ftp/incoming') as $sku => $slots) {
    $productId = $shopify->products()->findIdBySku($sku);
    if ($productId === null) {
        continue; // unknown SKU
    }
    $result = $shopify->products()->syncMedia($productId, $slots);
    // ['media' => [{key,id,action}], 'created'=>[...], 'replaced'=>[...], 'pruned'=>[...], 'orderedIds'=>[...], 'reorder'=>..., 'userErrors'=>[]]
}
```

For each slot `syncMedia()`:
1. uploads the local file (staged upload) — or uses the URL for `MediaEntry::url()`;
2. if a media with the same key (`alt`, defaulting to the filename) already exists,
   **replaces its bytes in place via `fileUpdate`** (no duplicate file) — otherwise
   creates it;
3. **reorders** all touched media so positions match (`_1` → first, `_2` → second…).

So re-dropping `ACME-1_1.jpg` with new content swaps that one image and keeps the
order, instead of piling up duplicates.

By default it's additive (it never removes media you didn't list). Pass
**`prune: true`** to make it a *mirror* — keyed media not in the new set are deleted
so the product ends up with exactly the supplied images (handy after renames):

```php
$shopify->products()->syncMedia($productId, $slots, prune: true);
// $result['pruned'] === the media gids that were removed
```

The low-level pieces are available too: `files()->upload($path)`,
`files()->update([...])`, `products()->reorderMedia($id, $orderedIds)`, and
`products()->deleteMedia($id, $mediaIds)`.

## Variants, prices & inventory

Prices and SKUs live on the **variant**, and stock lives on the variant's
**inventory item** at a **location** — so they're handled by `variants()` and
`inventory()`, not the product directly.

```php
use Baldie81\ShopifyGraphQL\Support\VariantInput;

// Create a product WITH options + variants in one call (productSet):
$shopify->products()->set([
    'title'          => 'Classic Tee',
    'productOptions' => [['name' => 'Color', 'values' => [['name' => 'Red'], ['name' => 'Blue']]]],
    'variants'       => [
        ['optionValues' => [['optionName' => 'Color', 'name' => 'Red']],  'price' => '19.90', 'inventoryItem' => ['sku' => 'TEE-RED']],
        ['optionValues' => [['optionName' => 'Color', 'name' => 'Blue']], 'price' => '19.90', 'inventoryItem' => ['sku' => 'TEE-BLUE']],
    ],
]);

// Prices: read variants, then set price / compareAtPrice and enable tracking.
$variants = $shopify->variants()->list($productId);   // id, sku, price, compareAtPrice, inventoryItem { id, tracked }
$shopify->variants()->update($productId, [
    VariantInput::make($variants[0]['id'])->price(17.50)->compareAtPrice(24.00)->tracked(),
]);

// Inventory: pick a location, then set absolute quantities (or adjust by delta).
$location = $shopify->inventory()->locations()[0]['id'];
$shopify->inventory()->setQuantities([
    ['inventoryItemId' => $variants[0]['inventoryItem']['id'], 'locationId' => $location, 'quantity' => 100],
]);
// also: adjustQuantities([... 'delta' => -5 ...]) and activate($inventoryItemId, $location, 100)
```

An inventory item must be **tracked** (`VariantInput::tracked()`) and stocked at the
location before a quantity sticks — `setQuantities()` works once stocked; `activate()`
stocks it if not.

These bulk methods (`variants()->create/update/delete()`,
`inventory()->setQuantities/adjustQuantities()`) **auto-chunk** at Shopify's
250-per-call cap — pass as many as you like and they split into multiple requests,
merging the results. Variant mutations are scoped to one product; `setQuantities`
is not, so a single call can stock items across many products. For mass
cross-product writes, see the bulk-mutation path under "Bulk operations".

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

$bulk->current('QUERY');                  // most recent op of a type
$bulk->cancel($op['id']);
```

**Bulk imports (mutations)** run a mutation once per JSONL line. `importMutation()`
does the whole flow — serialise the rows to JSONL, upload them as a
`BULK_MUTATION_VARIABLES` staged file, and start the operation:

```php
$started = $shopify->bulkOperations()->importMutation(
    'mutation call($input: ProductInput!) { productUpdate(input: $input) { product { id } userErrors { field } } }',
    [
        ['input' => ['id' => 'gid://shopify/Product/1', 'title' => 'A']],
        ['input' => ['id' => 'gid://shopify/Product/2', 'title' => 'B']],
    ],
);
$final = $shopify->bulkOperations()->poll($started['bulkOperation']['id']);
// (low level: $bulk->runMutation($mutation, $stagedUploadPath) if you staged the JSONL yourself)
```

A bulk mutation can't perform per-file byte uploads, so media rows must reference
already-hosted/staged URLs — for local files use `Products::syncMedia()` instead.

## Translations

Every registered translation must carry the `translatableContentDigest` of the
source content, so the flow is always *read digests, then register*. `set()` does
both — you just pass a locale and a map of values.

The translatable **product** fields are `title`, **description (Shopify's key is
`body_html`)**, `handle`, and `product_type`, plus the SEO `meta_title` /
`meta_description` when customised. Translate them fluently with `ProductField` —
each field knows its Shopify key, and one call covers every field and locale:

```php
use Baldie81\ShopifyGraphQL\Support\ProductField;

$result = $shopify->products()->setTranslations($productId, [
    ProductField::title()->withTranslation('fr', 'Chapeau de paille'),
    ProductField::description()
        ->withTranslation('fr', '<p>Un chapeau de paille</p>')
        ->withTranslation('es', '<p>Un sombrero de paja</p>'),
    ProductField::handle()->withTranslation('fr', 'chapeau-de-paille'),
    ProductField::productType()->withTranslation('fr', 'Chapeau'),
]);
// returns { translations, userErrors, skipped }; non-translatable fields land in `skipped`.
```

### Translate while creating (no id needed)

You can't translate in the same `productCreate` call — Shopify needs the product to
exist first (for its GID and content digests). But you can declare a field's base
value *and* its translations together, attach them to the input, and let `create()`
(or `update()`) register them for you right after the product is saved:

```php
use Baldie81\ShopifyGraphQL\Support\ProductInput;
use Baldie81\ShopifyGraphQL\Support\ProductField;

$result = $shopify->products()->create(
    ProductInput::make()
        ->field(ProductField::title('A nice t-shirt')->withTranslation('fr', 'Un beau t-shirt'))
        ->field(ProductField::description('<p>Soft cotton</p>')->withTranslation('fr', '<p>Coton doux</p>'))
        ->vendor('Acme')->status(ProductStatus::Active)   // non-translatable fields as usual
);
// $result['product']  — the created product
// $result['translations'] — the registered translations (present when fields carried any)
```

Under the hood that's `productCreate` → look up digests → `translationsRegister`, but
it reads as one call. The base values populate the create input; the translations are
registered against the new product id.

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

## Return data (built for persistence)

Every write method returns the identifiers and errors you'd want to store, rather
than just a success flag:

| Method | Returns |
|--------|---------|
| `create()` / `update()` | `product` (incl. `id` GID and `legacyResourceId` numeric id), `userErrors`, and `translations` when fields carried any |
| `get()` / `search*()` | product nodes with `id` + `legacyResourceId` + timestamps |
| `setMetafields()` | `metafields` (`id` GID, `legacyResourceId`, `namespace/key/type/value`, timestamps), `userErrors` |
| `syncMedia()` | `media` (`{ key, id (GID), action }` per slot), `created`/`replaced`/`pruned`, `orderedIds`, `reorder.job`, aggregated `userErrors` |
| `addMedia()` / `ensureMedia()` / `deleteMedia()` | media nodes / `deletedMediaIds` with `id`s, plus `mediaUserErrors` |
| `setTranslations()` / `translations()->set()/register()` | `translations` (each with `translatableContentDigest`), the `digests` map, `userErrors`, `skipped` |
| `bulkOperations()` | `bulkOperation` (`id`, `status`, `url`, `objectCount`, …), `userErrors` |
| `files()->upload()` | the `resourceUrl`; `update()` returns files + `userErrors` |

Notes:
- **Numeric ids:** products/metafields include `legacyResourceId`; for *any* GID,
  `Gid::id($gid)` returns the trailing numeric id.
- **Errors are data, never swallowed:** `userErrors`/`mediaUserErrors` come back on
  the result for logging (only transport/throttle/HTTP failures throw).
- **Digests:** persist the per-translation `translatableContentDigest` so you can
  later detect "outdated" translations or re-register safely.

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

Other resources (orders, customers…), caller-supplied selection sets, and
contextual/market pricing (price lists). The Products slice is the template the
rest follows.

## License

MIT.
