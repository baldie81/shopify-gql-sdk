# Shopify Admin GraphQL PHP SDK

A small, **framework-independent** PHP client for the Shopify **Admin GraphQL**
API. No framework coupling — just PHP 8.3+ and Guzzle. Shopify is deprecating the
REST Admin API, so this is GraphQL-only from the start.

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
- **`Resource\Customers`** — get / search / create / update, plus metafields.
- **`Resource\Orders`** — read/search, fulfillment, refunds, and metafields
  (e.g. writing ERP sync state back onto the order).
- **`Resource\DraftOrders`** — create orders (manual / phone / B2B): build a draft,
  then `complete()` it into a real Order.
- **`Resource\FulfillmentOrders`** — the fulfillment-order lifecycle beneath
  `orders()->fulfill()`: holds, moves, close/cancel/open/reschedule, merge/split,
  and the 3PL request flow (submit/accept/reject fulfillment + cancellation).
- **`Resource\Collections`** — manual & smart collections, product membership
  (`addProducts` sync; `removeProducts`/`reorderProducts` async jobs), metafields.
- **`Resource\PriceLists`** — contextual/market/B2B pricing: price lists plus
  fixed-price management (`setPrices`/`updatePrices`/`deletePrices`).
- **`Resource\GiftCards`** — issue gift cards (one-time code), credit/debit the
  balance, and deactivate (no delete).
- **`Resource\Webhooks`** — webhook subscriptions: subscribe a topic to an
  endpoint (version-aware `uri`/`callbackUrl`), list/update/delete.
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
- **`Support\CustomerInput`** / **`Support\Address`** — builders for customers and mailing addresses.
- **`Support\CollectionInput`** / **`Support\PriceListInput`** / **`Support\DraftOrderInput`** / **`Support\GiftCardInput`** — builders for collections, price lists, draft orders and gift cards.
- **`Support\Metafield`** — builder for metafields of any type (serialises the value).
- **`Support\Media`** — `CreateMediaInput` builder; its `key` (the filename by default) is what `ensureMedia()` dedupes on.
- **`Support\MediaEntry` / `Support\MediaFolder`** — positioned media + parsing a
  folder of `SKU_1.jpg`, `SKU_2.jpg`, … drops.
- **`Support\Translation`** — `TranslationInput` builder (digest optional).
- **`Support\ProductField`** — fluent per-field translations (`title()->withTranslation(...)`).
- **`Support\Gid`** — global-ID helpers (`gid://shopify/Product/123`).
- **`Enums`** — `ProductStatus`, `MetafieldType`, `MediaContentType`,
  `BulkOperationStatus`, `BulkOperationType`, `WebhookTopic`, `ProductTranslatableField`.
- **`Exceptions`** — `GraphQLException` (top-level errors / non-2xx), `ThrottledException`
  (cost-limit / 429), `TransportException`, `UploadException`, `PollTimeoutException`,
  `MediaKeyStoreException`,
  under a `ShopifyException` base.

## Quick start

```php
use Baldie81\ShopifyGraphQL\Configuration;
use Baldie81\ShopifyGraphQL\ShopifyClient;

$shopify = ShopifyClient::create(new Configuration(
    shop:        'my-store',          // or 'my-store.myshopify.com'
    accessToken: 'shpat_...',         // Admin API access token
    apiVersion:  '2026-10',           // dated, quarterly; ~12-month support window
));

$product = $shopify->products()->get('123');   // numeric id or a GID
```

`shop` accepts a handle, a `*.myshopify.com` hostname or a pasted URL; anything
else throws `InvalidArgumentException` up front. Optional settings: `timeout`,
`connectTimeout`, `verifySsl` (these also apply to staged uploads and bulk-result
downloads) and `maxRetries` (default 2).

Already have a configured Guzzle client? `ShopifyClient::fromGuzzle($guzzle)` reads
the API version from its `base_uri` (`…/admin/api/<version>/`).

### Security notes

- **The access token only goes to `*.myshopify.com`.** A host outside it (a
  proxy, a mock server) needs `allowCustomHost: true` on the `Configuration`.
  Leave it off whenever `shop` comes from user input — an OAuth parameter, a
  form — or the token would be sent to whatever host was typed in.
- **Redirects are not followed** on the API client. If you build your own with
  `fromGuzzle()`, give it `'allow_redirects' => false` too: Guzzle re-sends
  custom headers, the token among them, when a redirect leads to another host.
- **Dumps.** `print_r()` / `var_dump()` / `json_encode()` of the `Configuration`
  and the clients hide the token, and a `Configuration` refuses to be
  serialized. Exception *messages* never contain it, but the previous
  (Guzzle) exception attached to a `TransportException` holds the request,
  headers included — don't dump exception objects wholesale into logs.
- **`$fields` selections are spliced into the query as written.** Build them
  in code; never from user input.

### Protected customer data

Shopify only returns customer names, emails, phone numbers and addresses to apps
approved for those fields (custom apps have the access; public apps go through
review). For a field the app may not read, Shopify sends `null` *and* an `errors`
entry, which the SDK throws as a `GraphQLException` — the rest of the response is
on the exception's `data`.

So that a missing approval doesn't break unrelated reads, the default selections
of `orders()`, `draftOrders()` and `giftCards()` only include `customer { id }`.
Ask for more with `fields:` when your app has the access. `customers()` reads
names, email, phone and the default address by default; pass a narrower
`fields:` if your app isn't approved for them.
- **Drop folders.** `MediaFolder::scan()` skips symbolic links, so a folder
  others can write to can't point an upload at a file elsewhere on the machine.

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

Shopify does **not** dedupe media by source, so attaching the same image twice
creates a duplicate file. `ensureMedia()` fixes that: every media has a **key** (by
default the source's filename), and only media whose key isn't on the product yet
is created — so re-runs never add the same asset twice.

```php
use Baldie81\ShopifyGraphQL\Support\Media;

$result = $shopify->products()->ensureMedia($productId, [
    Media::image('https://cdn.example.com/front.jpg', alt: 'Red tee, front', key: 'front'),
    Media::image('https://cdn.example.com/back.jpg',  alt: 'Red tee, back',  key: 'back'),
]);
// $result === ['added' => [...], 'skipped' => ['front'], 'mediaUserErrors' => [], 'keyStoreSaved' => true]

// Run it again with the same input → everything is skipped, nothing duplicated.
$shopify->products()->addMedia($productId, [...]);  // raw, non-idempotent escape hatch
```

### How media is recognised (key stores)

The SDK remembers which image belongs to which key in a small map per product —
`{"ACME_1.jpg": "gid://shopify/MediaImage/123", …}` — and recognises existing media
**by id**. Alt text is therefore free for real descriptions, and editing it in the
Shopify admin doesn't cause a duplicate on the next sync.

Where the map lives is pluggable:

| Store | Where | Notes |
|---|---|---|
| `MetafieldMediaKeyStore` (**default**) | JSON metafield `shopify_gql_sdk.media_keys` on the product | Nothing to set up; travels with the store; read in the same request as the media. Not shown on the product page (no definition) and not exposed to the storefront. |
| `JsonFileMediaKeyStore` | One JSON file per product under a directory you choose | Directory is created on first write. Must persist across deploys and be backed up; local to one machine. |
| `ChainMediaKeyStore` | Several stores in priority order | For each key, the first store with a still-valid id wins; all are written. |
| `NullMediaKeyStore` | Nowhere | Opt out: match by alt text only, as before 1.4. |

```php
use Baldie81\ShopifyGraphQL\MediaKeys\{ChainMediaKeyStore, JsonFileMediaKeyStore, MetafieldMediaKeyStore};

// Metafield first, a local JSON copy second (e.g. CodeIgniter 4's writable folder):
$shopify->useMediaKeyStore(new ChainMediaKeyStore(
    new MetafieldMediaKeyStore($shopify->graphql()),
    new JsonFileMediaKeyStore(WRITEPATH . 'shopify/media-keys'),
));
```

Implement `MediaKeyStore` (`load` / `save` / `forget`) to keep the map in your own
database instead. Besides your keys, the map carries one reserved entry,
`@product` (`MediaKeyStore::OWNER_KEY`), holding the GID of the product it was
written for — store it and return it like any other entry.

**Matching rules**

1. A key maps to the media id stored for it, provided that media is still on the
   product. Entries for media that is gone are dropped.
2. A map names the product it was written for. A map naming another product —
   copied along when a product is duplicated in the admin — is ignored.
3. Only when the product has **no map of its own** and no stored entry is usable —
   a product synced before 1.4, or a duplicated/migrated product — is existing
   media **adopted by its alt text**, once. The map is then written, and from the
   next sync on rule 1 applies. Adopted keys are listed in the result (`adopted`).
   A product that has its own map is never adopted from, even if every image the
   map tracked has since been deleted.

**Alt text.** Pass `alt:` for a real description. Without it, a new image gets its
key as alt text and a replaced image keeps the alt text it already has. An `alt:`
you pass is applied on every sync, so changing it in your code updates the image.

> **With `Media`, pass `key:` whenever you pass a descriptive `alt:`.**
> `Media::image($url, alt: 'Red tee, front')` has no key of its own, so the alt
> text is used as the key (as in earlier versions). Rewording the description
> later changes the key, and `ensureMedia()` adds the image again. Write
> `Media::image($url, alt: 'Red tee, front', key: 'front')` instead.
> `MediaEntry` is not affected: its key is always the filename or the `key:` you give.

**Things to know**

- Rule 3 needs the key in the alt text. If you set your own alt text **and** a
  product is later duplicated in the admin or the store is migrated, the copy's
  first sync can't recognise its images and adds them again. A second store that
  isn't copied with the product (the JSON file, or your database) doesn't help
  here either, because the copy has a new product id. With `prune: true` the
  unrecognised originals are removed in that same sync, so the product still ends
  up with one of each; without it you get duplicates. Leaving `alt:` unset avoids
  the problem entirely.
- If the map can't be saved after media was changed, the call still returns, with
  `keyStoreSaved: false` and the reason in `userErrors`. Sync again to repair it.
- If no store can be read at all, the call throws `MediaKeyStoreException` before
  changing anything.
- Upgrade every process that syncs a store: media added by an older version after
  a product has a map isn't recognised.
- Never point `JsonFileMediaKeyStore` at a cache directory that gets cleared.

Media is attached with `productUpdate(media: …)` and removed with `fileUpdate`
(the older `productCreateMedia` / `productDeleteMedia` are deprecated by Shopify).

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
    // ['media' => [{key,id,action}], 'created'=>[...], 'replaced'=>[...], 'pruned'=>[...], 'adopted'=>[...],
    //  'orderedIds'=>[...], 'reorder'=>..., 'keyStoreSaved'=>true, 'userErrors'=>[]]
}
```

For each slot `syncMedia()`:
1. uploads the local file (staged upload) — or uses the URL for `MediaEntry::url()`;
2. if the slot's key (the filename by default) is already on the product,
   **replaces its bytes in place via `fileUpdate`** (no duplicate file) — otherwise
   creates it;
3. **moves** each slot's image to its position on the product (`_1` → first,
   `_2` → second…). Positions are absolute: dropping only `_2` and `_3` leaves the
   first image where it is, and a single new `_1` becomes the main image. Images
   the sync doesn't name keep their order and fill the remaining places. Nothing
   is sent when everything is already in place.

So re-dropping `ACME-1_1.jpg` with new content swaps that one image and keeps the
order, instead of piling up duplicates.

By default it's additive (it never removes media you didn't list). Pass
**`prune: true`** to make it a *mirror* — media the SDK tracks that isn't in the new
set is removed, so the product ends up with exactly the supplied images (handy
after renames). Images the SDK doesn't track, such as a merchant's own uploads, are
left alone. The one exception is a product's first sync (rule 3 above): images
adopted by their alt text count as tracked, so with `prune: true` any of them not
in the new set is removed. If that first sync did *not* prune, an adopted image
is only pruned later once some sync has named its key — until then it could just
as well be a merchant upload that happened to have alt text:

```php
$shopify->products()->syncMedia($productId, $slots, prune: true);
// $result['pruned'] === the media gids that were removed
```

To set real alt text, build the slots yourself (the filename stays the key):

```php
use Baldie81\ShopifyGraphQL\Support\MediaEntry;

$shopify->products()->syncMedia($productId, [
    MediaEntry::file(1, '/ftp/incoming/ACME-1_1.jpg', alt: 'Red tee, front'),
    MediaEntry::file(2, '/ftp/incoming/ACME-1_2.jpg', alt: 'Red tee, back'),
]);
```

The low-level pieces are available too: `files()->upload($path)`,
`files()->update([...])`, `files()->delete($ids)`,
`products()->reorderMedia($id, $orderedIds)`, and
`products()->deleteMedia($id, $mediaIds)`.

`deleteMedia()` (and `prune`) **detaches** media from the product — the file stays
in the store's Files library and on any other product using it. Pass
`deleteFiles: true` to delete the files outright.

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
    VariantInput::make($variants[0]['id'])->price('17.50')->compareAtPrice('24.00')->tracked(),
]);

// Add a variant to an existing product:
$shopify->variants()->create($productId, [
    VariantInput::new()->optionValue('Color', 'Green')->price('19.90')->sku('TEE-GREEN'),
]);

// Inventory: pick a location, then set absolute quantities (or adjust by delta).
$location = $shopify->inventory()->locations()[0]['id'];
$shopify->inventory()->setQuantities([
    ['inventoryItemId' => $variants[0]['inventoryItem']['id'], 'locationId' => $location, 'quantity' => 100],
]);
// also: adjustQuantities([... 'delta' => -5 ...]) and activate($inventoryItemId, $location, 100)
// add 'changeFromQuantity' => N to an entry of either to apply it only if the current quantity is N
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

**API 2026-04 compatibility** is handled automatically (the client is version-aware
via `Configuration::apiVersion`). On 2026-04+, the inventory and `orders()->refund()`
mutations attach the now-mandatory `@idempotent` directive (a UUID is generated per
call/chunk; pass `idempotencyKey:` for your own retry-safe key), and `setQuantities`
sends the now-required `changeFromQuantity` as `null` — an unconditional set, with
no extra read. Supply `'changeFromQuantity' => N` in an entry to opt into a guarded
(compare-and-swap) set. On 2026-01 the directive isn't required and isn't sent;
`changeFromQuantity` works the same way there.

**Amounts.** Pass money as **strings** (`'17.50'`): they're sent digit-for-digit,
so three-decimal currencies survive. Floats are accepted as a convenience. Anything
that isn't a plain decimal (`'1,50'`, `'€5'`) throws `InvalidArgumentException`
instead of being silently coerced.

**Lists are complete.** `variants()->list()`, `collections()->products()`,
`priceLists()->prices()`, `metafieldDefinitions()->all()`, `inventory()->locations()`,
`customers()->addresses()`, `products()->media()` and
`fulfillmentOrders()->forOrder()` walk every page. Pass a number as `$first` to cap
how many come back.

## Bulk operations

Run a query (export) or mutation (import) that Shopify processes asynchronously and
returns as a JSONL file. Kick off, poll to a terminal status, then stream the rows.

```php
$bulk = $shopify->bulkOperations();

$op = $bulk->runQuery('{ products { edges { node { id title } } } }')['bulkOperation'];
$final = $bulk->poll($op['id']);          // waits until COMPLETED/FAILED/CANCELED/EXPIRED
// Still running after the budget (default 300 polls × 2s)? poll() throws
// PollTimeoutException — $e->operation is the last-seen state; poll again or cancel.

if (($final['status'] ?? null) === 'COMPLETED') {
    foreach ($bulk->results($final) as $row) {   // downloads `url`, yields one decoded line at a time
        // $row e.g. ['id' => 'gid://shopify/Product/1', 'title' => '…']
    }
}
// on failure: $final['status'] === 'FAILED' with $final['errorCode']; results($final, partial: true) reads partialDataUrl

$bulk->current(\Baldie81\ShopifyGraphQL\Enum\BulkOperationType::Query); // most recent op of a type (or 'QUERY')
$bulk->list('status:running');            // recent operations, newest first (2026-01+)
$bulk->cancel($op['id']);
```

**Bulk imports (mutations)** run a mutation once per JSONL line. `importMutation()`
does the whole flow — serialise the rows to JSONL, upload them as a
`BULK_MUTATION_VARIABLES` staged file, and start the operation:

```php
$started = $shopify->bulkOperations()->importMutation(
    'mutation call($product: ProductUpdateInput!) { productUpdate(product: $product) { product { id } userErrors { field } } }',
    [
        ['product' => ['id' => 'gid://shopify/Product/1', 'title' => 'A']],
        ['product' => ['id' => 'gid://shopify/Product/2', 'title' => 'B']],
    ],
);
$final = $shopify->bulkOperations()->poll($started['bulkOperation']['id']);
foreach ($shopify->bulkOperations()->results($final) as $row) {
    // each row is your mutation's payload — select id/userErrors in the mutation to get them here
}
// (low level: $bulk->runMutation($mutation, $stagedUploadPath) if you staged the JSONL yourself)
```

The result JSONL contains exactly the fields your query/mutation *selected*, so
include `id` (and `userErrors` for mutations) to get the values to persist/log.

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

## Customers & orders

```php
use Baldie81\ShopifyGraphQL\Support\CustomerInput;
use Baldie81\ShopifyGraphQL\Support\Metafield;

// Customers — get / search / create / update (+ metafields, e.g. an ERP id).
$res = $shopify->customers()->create(
    CustomerInput::make('jane@example.com')->firstName('Jane')->lastName('Doe')->tags(['vip'])
);
$shopify->customers()->setMetafields($res['customer']['id'], [Metafield::text('erp', 'customer_id', 'C-1')]);
$shopify->customers()->searchAll('tag:vip');

// Addresses use the dedicated mutations (customerAddressCreate/Update/Delete):
use Baldie81\ShopifyGraphQL\Support\Address;

$shopify->customers()->addAddress($res['customer']['id'],
    Address::make()->address1('500 Congress Ave')->city('Austin')->provinceCode('TX')->zip('78701')->countryCode('US'),
    setAsDefault: true,
);
$shopify->customers()->addresses($customerId);                 // list a customer's addresses
$shopify->customers()->updateAddress($customerId, $addressId, Address::make()->city('Dallas'));
$shopify->customers()->deleteAddress($customerId, $addressId);

// Reads include `defaultAddress` cheaply; pass withAddresses for the full list:
$shopify->customers()->get($customerId, withAddresses: true);          // -> customer.addresses.nodes[] (first 50)
$shopify->customers()->searchAll('tag:vip', withAddresses: true);
```

Orders aren't *created* via the API here (that's checkout / draft orders) — the
resource covers **read, fulfillment, refunds**, and writing state back onto the order:

```php
$order = $shopify->orders()->get($orderId);                  // financial/fulfillment status, totals, customer
$shopify->orders()->searchAll('financial_status:paid');

// Fulfill all open fulfillment orders, with tracking. An order split across
// locations gets one fulfillment per location: all are in $result['fulfillments'].
$shopify->orders()->fulfill($orderId, ['number' => '1Z999', 'company' => 'UPS'], notifyCustomer: true);

// Refund (RefundInput body; orderId injected):
$shopify->orders()->refund($orderId, [
    'note' => 'Damaged', 'refundLineItems' => [['lineItemId' => $lineItemId, 'quantity' => 1]],
]);

// Write ERP integration state back onto the order (id, success flag, message):
$shopify->orders()->setMetafields($orderId, [
    Metafield::text('erp', 'order_id', 'SO-1001'),
    Metafield::boolean('erp', 'synced', true),
    Metafield::text('erp', 'message', 'Sent to ERP OK'),
]);
```

Metafield **definitions** for these owners: `metafieldDefinitions()->defineOrder(...)`
and `defineCustomer(...)` (alongside `defineProduct(...)`).

## Draft orders (creating orders)

Orders aren't created directly — you build a **draft order** and complete it.

```php
use Baldie81\ShopifyGraphQL\Support\DraftOrderInput;

$draft = $shopify->draftOrders()->create(
    DraftOrderInput::make()
        ->customer($customerId)                  // purchasingEntity under the hood
        ->lineItem($variantId, 2)                // a catalog variant
        ->customLineItem('Gift wrap', '5.00')    // a custom (non-catalog) line
        ->useCustomerDefaultAddress(),
);

// Turn the draft into a real Order (paymentPending: true to leave it unpaid):
$completed = $shopify->draftOrders()->complete($draft['draftOrder']['id']);
$orderId   = $completed['draftOrder']['order']['id'];
```

`calculate()` returns totals without saving; `update()` / `delete()` and
`searchAll()` round out the resource.

## Custom field selection

Reads use a lean default selection, but you can pass your own selection set as the
optional `$fields` argument to override it:

```php
$shopify->products()->get($id, fields: 'id handle variants(first: 50) { nodes { id sku price } }');
$shopify->orders()->searchAll('financial_status:paid', fields: 'id name lineItems(first: 100) { nodes { title quantity } }');
```

Available on `get()` / `search()` / `searchAll()` for Products, Orders, Customers,
Collections and DraftOrders, plus `variants()->list()`. Pass `null` (the default)
to keep the built-in selection.

## Gift cards

```php
use Baldie81\ShopifyGraphQL\Support\GiftCardInput;

$result = $shopify->giftCards()->create(GiftCardInput::make('25.00', 'EUR')->note('Loyalty reward'));
$code   = $result['giftCardCode'];   // ⚠ the plaintext code is returned ONLY here — capture it

// Other currency, sent to a recipient with a personal message on a schedule
$shopify->giftCards()->create(
    GiftCardInput::make('50.00', 'USD')
        ->customer($buyerId)
        ->recipient($friendId, message: 'Happy birthday!', preferredName: 'Alex', sendAt: '2026-12-01T09:00:00Z')
);

$shopify->giftCards()->credit($id, '10.00', 'EUR');   // add funds
$shopify->giftCards()->debit($id, '5.00', 'EUR');     // remove funds
$shopify->giftCards()->deactivate($id);               // permanent — there is no delete
```

Balance changes go through `credit()`/`debit()` (never `update()`), and a gift
card can only be **deactivated**, not deleted.

## Webhooks

```php
use Baldie81\ShopifyGraphQL\Enum\WebhookTopic;

$shopify->webhooks()->create(
    WebhookTopic::OrdersCreate,                    // or a raw topic string
    'https://example.com/webhooks/orders',         // the endpoint
    ['includeFields' => ['id', 'name']],
);

$shopify->webhooks()->all();
$shopify->webhooks()->delete($id);
```

You always pass the endpoint as a `uri`; the SDK maps it to `callbackUrl`
automatically on ≤2025-07 (where `uri` doesn't exist yet) and uses `uri` on
2025-10+. Verify the `X-Shopify-Hmac-Sha256` header on the receiving end.

## Return data (built for persistence)

Every write method returns the identifiers and errors you'd want to store, rather
than just a success flag:

| Method | Returns |
|--------|---------|
| `create()` / `update()` | `product` (incl. `id` GID and `legacyResourceId` numeric id), `userErrors`, and `translations` when fields carried any |
| `get()` / `search*()` | product nodes with `id` + `legacyResourceId` + timestamps |
| `setMetafields()` | `metafields` (`id` GID, `legacyResourceId`, `namespace/key/type/value`, timestamps), `userErrors` |
| `syncMedia()` | `media` (`{ key, id (GID), action }` per slot; `action` is `created`, `replaced`, or `unchanged` when Shopify rejected the replace), `created`/`replaced`/`pruned`/`adopted`, `orderedIds`, `reorder.job`, `keyStoreSaved`, aggregated `userErrors` |
| `addMedia()` / `ensureMedia()` / `deleteMedia()` | the created media nodes / `deletedMediaIds` with `id`s, plus `mediaUserErrors` |
| `setTranslations()` / `translations()->set()/register()` | `translations` (each with `translatableContentDigest`), the `digests` map, `userErrors`, `skipped` |
| `bulkOperations()` | `bulkOperation` (`id`, `status`, `url`, `objectCount`, …), `userErrors` |
| `files()->upload()` | the `resourceUrl`; `update()` returns files + `userErrors` |

Notes:
- **Numeric ids:** products/metafields include `legacyResourceId`; for *any* GID,
  `Gid::id($gid)` returns the trailing numeric id.
- **Errors are data, never swallowed:** `userErrors`/`mediaUserErrors` come back on
  the result for logging. Transport, throttle and HTTP failures throw — including
  auth failures (401/403), which Shopify reports as a bare `{"errors": "…"}` string.
- **Digests:** persist the per-translation `translatableContentDigest` so you can
  later detect "outdated" translations or re-register safely.

## GraphQL specifics (how this differs from a REST SDK)

- **Selection sets.** Reads return a curated default field set (`Products::FIELDS`),
  kept lean because Shopify bills by query cost. Pass your own with `fields:`
  (see [Custom field selection](#custom-field-selection)).
- **Cursor pagination.** No page numbers — connections expose `pageInfo
  { hasNextPage, endCursor }`; `searchAll()` walks `after` until exhausted (page
  size capped at Shopify's max of 250).
- **Three error layers.** (1) transport (`TransportException`), (2) top-level
  `errors[]` / non-2xx (`GraphQLException`, incl. `ThrottledException`), and
  (3) **`userErrors`** inside mutation payloads — business validation returned as
  **data, not thrown**, so you inspect `$result['userErrors']`.
- **Throttling & retries.** The client detects `extensions.code = THROTTLED` and
  HTTP 429, backs off (from `extensions.cost` / `Retry-After`) and retries —
  `Configuration::maxRetries`, default 2. A transient 502/503/504, or a
  connection failure/timeout, is retried for **queries only**: a mutation may already have been applied, so it is never
  blindly re-sent. With `graphql()->execute()`, a document counts as a query when
  its first token (after any comments) is `query` or `{`.
- **GIDs.** Everything is identified by `gid://shopify/<Type>/<id>`; helpers coerce
  numeric ids and extract them back.
- **Versioning.** The dated `apiVersion` is part of the endpoint and defaults to
  `2026-10`. Pin it explicitly in production. The versions Shopify currently
  serves — `2026-01`, `2026-04`, `2026-07` and `2026-10`
  (`Configuration::SUPPORTED_API_VERSIONS`) — are all supported: the SDK adapts
  version-specific inputs (2026-04 idempotency, `changeFromQuantity`, gift card
  `initialAmount`/`initialValue`) to whatever you configure, and every operation
  is validated against each version's schema. Older versions are accepted but
  can't be checked, since Shopify no longer publishes their schemas.

## Examples

Copy-pasteable usage lives in [`examples/`](examples) — **one class per area**,
each method demonstrating a single operation against the real public API. Build a
client with `ExampleClient::create()` (edit its dummy placeholder credentials
first — never commit real tokens), then call a class, e.g.
`(new ProductExamples($shopify))->create()`.

- [ProductExamples](examples/ProductExamples.php) — create / update / `set` / delete / search / metafields / find-by-SKU
- [VariantExamples](examples/VariantExamples.php) — list / create / update / delete (prices, sku, tracked)
- [InventoryExamples](examples/InventoryExamples.php) — locations, set/adjust quantities, activate
- [CustomerExamples](examples/CustomerExamples.php) — CRUD, metafields, addresses
- [OrderExamples](examples/OrderExamples.php) — read/search, fulfill, refund, ERP write-back
- [CollectionExamples](examples/CollectionExamples.php) — manual & smart collections, product membership
- [FulfillmentOrderExamples](examples/FulfillmentOrderExamples.php) — holds, move, cancel/close/reschedule, 3PL flow
- [PriceListExamples](examples/PriceListExamples.php) — price lists + fixed prices
- [DraftOrderExamples](examples/DraftOrderExamples.php) — create orders via draft orders (build → complete)
- [GiftCardExamples](examples/GiftCardExamples.php) — issue / credit / debit / deactivate gift cards
- [WebhookExamples](examples/WebhookExamples.php) — subscribe / list / update / delete webhook subscriptions
- [TranslationExamples](examples/TranslationExamples.php) — translate resources
- [MetafieldDefinitionExamples](examples/MetafieldDefinitionExamples.php) — define product/order/customer metafields
- [FileExamples](examples/FileExamples.php) — upload files, update in place
- [MediaKeyStoreExamples](examples/MediaKeyStoreExamples.php) — where media key maps are kept (metafield, JSON file, CodeIgniter 4 wiring)
- [BulkOperationExamples](examples/BulkOperationExamples.php) — bulk export/import with streamed results

## Tests

```bash
composer install
composer test
```

Unit tests mock Guzzle (no network). Because mocked responses can't tell you that
Shopify would reject a query, one test validates **every operation the SDK sends**
— documents, builder-produced variables, and use of deprecated fields — against
Shopify's real Admin schema, once per supported API version. It needs the schemas
downloaded once (a version without one is skipped):

```bash
composer schema    # fetch the public Admin GraphQL schemas into tests/fixtures/schema/
composer check     # PHPStan + the full test suite
```

For live usage, point an `ExampleClient` at a test store and run an example class.

See [CHANGELOG.md](CHANGELOG.md) for release notes.

## Not yet

Discounts / price rules and metaobjects.

## License

MIT.
