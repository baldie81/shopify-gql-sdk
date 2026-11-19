# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **`syncMedia(prune: true)` no longer removes a merchant's own images from a
  product that already has a key map.** When every image a map tracked had been
  deleted, the map counted as unusable, so the remaining images were adopted by
  their alt text and then pruned. The map now records the product it was written
  for (a reserved `@product` entry, `MediaKeyStore::OWNER_KEY`); a product with
  its own map is never adopted from, and a map copied from a duplicated product
  is ignored. Maps written by 1.4.0/1.4.1 gain the entry on their next sync —
  until then they behave as before. Custom `MediaKeyStore` implementations
  need no change as long as they store and return every entry of the map;
  `load()` on the bundled stores now returns the `@product` entry too.
- **`syncMedia()` no longer reports a replace that Shopify rejected.** A key
  whose `fileUpdate` failed is left out of `replaced` and its `media` entry has
  the action `unchanged` (it was `replaced` before); the error is in
  `userErrors` as before.
- **A mutation that doesn't begin with the word `mutation` is no longer retried
  on a 502/503/504.** A document passed to `graphql()->execute()` with a leading
  comment was taken for a query and could be sent twice. Only a document whose
  first token is `query` or `{` is retried now, so a query that opens with a
  fragment definition is no longer retried either.
- **`orders()->fulfill()` works for orders split across locations.** Shopify
  rejects a `fulfillmentCreate` whose fulfillment orders are assigned to
  different locations, so one fulfillment is now created per location. The
  result gains `fulfillments` (every fulfillment created); `fulfillment` is the
  first of them, unchanged for single-location orders.

### Internal

- CI: weekly scheduled run (catches Shopify schema changes and new dependency
  advisories), `composer validate --strict`, `composer audit`, and a job that
  runs the tests on the lowest allowed dependency versions.
- CI fails, instead of silently skipping the schema-validation test, when the
  Shopify schema can't be downloaded.

## [1.4.1] - 2026-11-18

### Documentation

- README and the `Media` class now spell out that a descriptive `alt:` passed to
  `Media` needs a `key:` alongside it; otherwise the alt text is the key, and
  rewording it later makes `ensureMedia()` add the image again.
- README notes that an `alt:` you pass is reapplied on every sync, and what
  `prune: true` does for a duplicated or migrated product with custom alt text.

### Internal

- CI uses `actions/checkout@v5`.

## [1.4.0] - 2026-11-18

Media is now recognised by id, through a stored key map, instead of by alt text.
This resolves the "known limitation" listed under 1.3.0.

### Behaviour changes

- **`syncMedia()` and `ensureMedia()` keep a `key => media id` map per product**,
  by default in a JSON metafield on the product (`shopify_gql_sdk.media_keys`).
  Upgrading therefore starts writing one metafield to each product you sync. To
  keep the old alt-only behaviour, call
  `$shopify->useMediaKeyStore(new NullMediaKeyStore())`.
- **Existing media is adopted automatically.** A product that has no map yet has
  its media matched by alt text once, exactly as before, and the map is written.
  Nothing is duplicated on upgrade.
- **Once a product has a map, alt text is no longer consulted.** Editing an
  image's alt text in the admin no longer causes a duplicate on the next sync.
- **A replaced image keeps its alt text** unless you pass one. Previously every
  sync overwrote it with the key.
- **`syncMedia(prune: true)` removes only media the SDK tracks.** Once a product
  has a map, images added outside the SDK are left alone even if they have alt
  text.
- `syncMedia()` throws `MediaKeyStoreException` — before changing anything — if
  no key store can be read.

### Added

- `MediaKeys\MediaKeyStore` interface with four implementations:
  `MetafieldMediaKeyStore` (default), `JsonFileMediaKeyStore` (one file per
  product; the directory is created on first write), `ChainMediaKeyStore`
  (several stores in priority order) and `NullMediaKeyStore` (opt out).
- `ShopifyClient::useMediaKeyStore()`; `Products` accepts a store in its
  constructor.
- Real alt text: `MediaEntry::file()` / `::url()` take `alt:`, and
  `Media::make()` / `::image()` take `key:` so `alt:` can be a description.
  Passing only `alt:` to `Media` still uses it as the key, as before.
- `syncMedia()` returns `adopted` (keys matched by alt during the one-time
  adoption) and `keyStoreSaved`; `ensureMedia()` returns `keyStoreSaved`. A map
  that can't be saved after media was changed is reported in the result
  (`code: MEDIA_KEY_STORE_FAILED`), not thrown.
- `MediaKeyStoreExamples`, including CodeIgniter 4 wiring.

### Fixed

- Newly created media is matched to its slot by alt text where that is
  unambiguous and by position otherwise, so two images with the same
  description are no longer confused.

### Known limitations

- If you set your own alt text and a product is later duplicated in the admin,
  or the store is migrated, the copy's first sync cannot recognise its images
  and adds them again.
- All processes syncing a store must run 1.4 or later: media added by an older
  version after a product has a map is not recognised.
- Two simultaneous syncs of the same product: the last one to save its map wins.

## [1.3.0] - 2026-11-18

A correctness release from a full review of the package. No method was removed
or renamed, but several calls now **fail loudly where they used to fail
silently** — read "Behaviour changes" before upgrading.

### Behaviour changes

- **Auth and other non-2xx failures always throw.** Shopify reports them as
  `{"errors": "…"}` — a string, not a list — which was skipped, so with a Guzzle
  client that had `http_errors` off a 401 made `products()->get()` return `null`
  ("not found"). Any non-empty `errors` member and any non-2xx status now raise
  `GraphQLException`, whatever the injected client's `http_errors` setting.
- **Invalid amounts throw `InvalidArgumentException`.** `'1,50'` used to be sent
  as `1.00`. Numeric strings are now sent digit-for-digit (`'10.005'` is no
  longer rounded to `10.01`, which broke three-decimal currencies).
- **`bulkOperations()->poll()` throws `PollTimeoutException`** when the operation
  is still running after the attempt budget, instead of returning the unfinished
  operation as if it were the result. The default budget is now 300 polls
  (10 minutes) instead of 60.
- **`products()->deleteMedia()` and `syncMedia(prune: true)` detach media** from
  the product rather than deleting the file (Shopify's replacement for the
  deprecated `productDeleteMedia`). Pass `deleteFiles: true` to delete outright.
- **List methods return every page.** `variants()->list()`,
  `collections()->products()`, `priceLists()->prices()`,
  `metafieldDefinitions()->all()`, `inventory()->locations()`,
  `customers()->addresses()`, `products()->media()` and
  `fulfillmentOrders()->forOrder()` used to stop silently at one page (250, or 50
  for locations/addresses). `$first` is now an optional cap on the total.
- **`products()->update()` no longer registers translations when the update
  returned `userErrors`.**
- **`Configuration` validates its input**: a shop that isn't a handle/hostname, or
  a malformed API version, throws `InvalidArgumentException`.
- `ShopifyClient::fromGuzzle()` rejects an `$apiVersion` that contradicts the
  client's `base_uri`.
- Requires `guzzlehttp/guzzle` `^7.15.2` (earlier 7.x releases have published
  security advisories).

### Fixed

- HTTP 429 is retried (honouring `Retry-After`) instead of throwing on the first
  attempt; a transient 502/503/504 is retried for queries. Mutations are never
  re-sent after a gateway error, since the write may already have been applied.
- `inventory()->setQuantities()` on 2026-04+ no longer reads the current
  quantities first. It sends `changeFromQuantity: null` (Shopify's documented
  opt-out), which removes a read-then-write race, an extra query, and a failure
  for items stocked at more than 50 locations.
- `products()->findIdBySku()` quotes and escapes the SKU, so SKUs containing
  spaces, colons or quotes match exactly.
- `syncMedia()` pairs newly created media with its slot by key instead of by list
  position, so a partially failed create can't attach ids to the wrong slots.
- `VariantInput::barcode()` sent the barcode inside `inventoryItem`, which has no
  such field; it is now a variant-level field.
- `VariantInput::make()` coerces a numeric id to a `ProductVariant` GID, like
  every other id in the SDK.
- `Configuration` built a broken endpoint for a shop pasted with a path
  (`my-store.myshopify.com/admin`).
- `orders()->fulfill()` considered only an order's first 50 fulfillment orders.
- `ThrottledException::$errors` was always empty.
- `bulkOperations()->results()` yielded `null` for a malformed JSONL line; it now
  throws `TransportException`. `importMutation()` no longer writes an empty line
  for a row that can't be JSON-encoded.
- Collection and price-list id lists accept integer ids.

### Changed

- Deprecated Shopify APIs are no longer used on current versions:
  `productCreateMedia` → `productUpdate(media:)`, `productDeleteMedia` →
  `fileUpdate`, `currentBulkOperation` → `bulkOperations` (2026-01+),
  `Customer.addresses` → `addressesV2` in `customers()->addresses()`.
- `addMedia()` returns `userErrors` (with `mediaUserErrors` kept as an alias) and
  reports only the media it created.
- `Products` shares the client's `Files`/`Translations` instances, and the
  staged-upload and bulk-download HTTP clients now honour `Configuration`'s
  `verifySsl` and `connectTimeout`.
- Throttle backoff is clamped to 10s (was 5s), enough to refill a full
  single-query cost.
- Minimum PHP lowered to 8.3; nothing in the package needs 8.4.

### Added

- **Schema-validation test**: every operation the SDK sends (document, variables,
  deprecated fields) is validated against Shopify's real Admin GraphQL schema.
  Run `composer schema` once to download it. This is how the `barcode` bug and
  the deprecations above were found.
- `Configuration::$maxRetries`; `ShopifyClient::fromGuzzle()` takes `$maxRetries`,
  `$uploader` and `$downloader`, and reads the API version from the `base_uri`.
- `VariantInput::new()` and `->optionValue()` for creating variants.
- `Support\Money::decimal()`.
- `files()->delete()`, `bulkOperations()->list()`.
- `DraftOrderInput::customLineItem()` takes an optional currency (sent as
  `originalUnitPriceWithCurrency`).
- Customer selections include `defaultEmailAddress` / `defaultPhoneNumber`, and
  webhook selections include `uri` (2025-10+), alongside the deprecated fields.
- PHPStan (level 6), a GitHub Actions workflow (PHP 8.3–8.5), `composer check`,
  and `.gitattributes` so tests/examples aren't shipped in dist archives.

### Deprecated

- `GiftCardInput::DEFAULT_CURRENCY` and the default `$currencyCode` argument: pass
  the currency explicitly. It becomes required in 2.0.
- Still selected or used for backward compatibility, to be removed in 2.0:
  `email` / `phone` in the default customer selection, the webhook `endpoint`
  selection, `collectionAddProducts` / `collectionRemoveProducts`, and
  currency-less `customLineItem()` prices.

### Known limitation

- Media idempotency (`ensureMedia()`, `syncMedia()`) is still keyed on the media's
  `alt` text. That key is visible as the image's alt text, and editing it in the
  admin breaks the match. See the README for the trade-off and the workaround.

### Removed

- `examples_local/` and `.idea/` files that were tracked despite being ignored.

## [1.2.0] - 2026-11-11

- Gift cards send `initialAmount` (a `MoneyInput`); added `GiftCardInput::recipient()`.
- Dropped the invalid `code` selection from `giftCardUpdate` userErrors.

## [1.1.0] - 2026-11-09

- Default to Shopify Admin API `2026-10`.

## [1.0.0] - 2026-06-21

- First stable release: products, variants, inventory, customers, orders,
  collections, fulfillment orders, price lists, draft orders, gift cards,
  webhooks, metafields and definitions, translations, files and bulk operations.

[Unreleased]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.4.1...HEAD
[1.4.1]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/baldie81/shopify-gql-sdk/releases/tag/v1.0.0
