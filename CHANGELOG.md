# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **The access token is no longer sent along on redirects.** The API client
  built by `ShopifyClient::create()` doesn't follow redirects any more; Guzzle
  re-sends custom headers, `X-Shopify-Access-Token` included, when a redirect
  leads to another host (even from https to http). A client passed to
  `fromGuzzle()` should set `'allow_redirects' => false` itself.
- **`Configuration` only accepts `*.myshopify.com` hosts by default.** Any
  hostname or IP address used to be accepted, so a `shop` value taken from
  user input could direct the token to another host. **If you connect through
  a custom host, pass `allowCustomHost: true`** — without it the constructor
  now throws `InvalidArgumentException`.
- The token no longer shows in `print_r()` / `var_dump()` / `json_encode()` of
  the `Configuration` or the clients, and a `Configuration` can't be serialized.
- Signed staged-upload and bulk-result URLs are stripped of their query string
  in exception messages.
- `MediaFolder::scan()` skips symbolic links.
- Pagination stops when a cursor doesn't advance, and `bulkOperations()->results()`
  refuses a "line" longer than 64 MB, so a misbehaving server can't make either
  run without end.

### Fixed

- **`inventory()->adjustQuantities()` works on API 2026-04 and later** (the
  default included). Shopify requires `changeFromQuantity` on every change
  there, even as `null`; it is now sent, as `setQuantities()` already did. A
  value you pass yourself is kept as a compare-and-swap guard.
- **`syncMedia()` puts each image at its slot position.** Slots used to be
  ordered among themselves only: syncing just `_2` and `_3` moved them to first
  and second place, displacing the main image, and a single slot was never
  moved at all, so a new `_1` stayed last. Positions are now absolute on the
  product, images the sync doesn't name keep their order, and no reorder is
  sent when everything is already in place.
- **Images adopted by alt text are no longer pruned by a later
  `prune: true`** unless a sync has named their key in the meantime. Adoption
  takes every image with alt text, a merchant's own included; such keys are
  now kept as unconfirmed (a reserved `@adopted` entry in the key map). A
  first sync that itself prunes behaves as before.
- **`products()->create()` / `update()` return the saved product when the
  attached translations fail**, with a `TRANSLATIONS_FAILED` entry in
  `userErrors`, instead of throwing — the product id used to be lost, and
  retrying the create made a duplicate.
- **Default media keys no longer collide for URLs without a filename.**
  `…/watch?v=AAA` and `…/watch?v=BBB` both got the key `watch`, so
  `ensureMedia()` skipped the second. When the last path segment has no
  extension, the query string is now part of the key. Keys of ordinary file
  URLs (`…/front.jpg?v=2`) are unchanged.
- A caller's inventory idempotency key is sent as given when one call is
  enough; the `-0`, `-1`, … suffix is only added when the input is chunked.
- `Metafield` writes very small or large floats in plain decimal notation
  (`0.00001`, not `1.0E-5`, which Shopify rejects).

### Changed

- **Default selections no longer ask for protected customer fields they don't
  need.** `orders()`, `draftOrders()` and `giftCards()` select `customer { id }`
  instead of `customer { id email }`: for an app not approved to read customer
  email, Shopify answers with an `errors` entry and every one of those reads
  threw. Pass `fields:` to read more.
- `customers()` no longer selects the deprecated `email` / `phone` fields; use
  `defaultEmailAddress.emailAddress` and `defaultPhoneNumber.phoneNumber`,
  which were already returned.
- `customers()->get()` / `search()` with `withAddresses: true` read the
  addresses through `addressesV2` (the plain `addresses` field is deprecated):
  they come back as `addresses.nodes`, the first 50, with
  `addresses.pageInfo.hasNextPage`.
- `webhooks()` reads a subscription's destination as `uri` only; the deprecated
  `endpoint` union is no longer selected on API 2025-10 and later.
- `GraphQLException` has a `data` property holding whatever Shopify returned
  alongside the errors.
- `syncMedia()`'s `orderedIds` lists the slots' media ids in slot order; it is
  no longer the list sent to the reorder call.
- `collections()->reorderProducts()` documents Shopify's limit of 250 moves per
  call (not split automatically: moves build on one another and each call runs
  as its own asynchronous job).

## [1.6.2] - 2026-11-19

### Fixed

- **`syncMedia()` / `ensureMedia()` no longer forget images they created when
  a later request fails.** The key map was written only at the very end, so an
  exception after the images were created — a reorder or prune that timed out,
  say, since mutations are never retried — left them unrecorded, and the next
  sync of a product that already had a map created them again. The map is now
  saved before the exception is rethrown.
- **`orders()->fulfill()` leaves `SCHEDULED` fulfillment orders alone.** They
  aren't due yet (later deliveries of a subscription or pre-order); including
  them made Shopify reject the fulfillment of the open ones at the same
  location, or fulfilled future deliveries early. Only `OPEN` and `IN_PROGRESS`
  fulfillment orders are fulfilled.
- **`products()->findIdBySku()` accepts an integer.** PHP turns an all-digit
  array key into an integer, so the documented
  `foreach (MediaFolder::scan($dir) as $sku => $slots)` loop threw a
  `TypeError` under `strict_types` for numeric SKUs.
- **`collections()->reorderProducts()` sends `newPosition` as a string**, as
  its `UnsignedInt64` type requires — an integer, as the method's own example
  showed, is now converted.
- A media key map mixing numeric and non-numeric keys is no longer rewritten
  when nothing changed.

## [1.6.1] - 2026-11-19

### Fixed

- **`giftCards()->create()` works on API 2026-01 and 2026-04.** It always sent
  `initialAmount`, which only exists from 2026-07, so Shopify rejected the
  input on the two older versions. There it now sends `initialValue`; those
  versions issue the card in the shop's currency, so the currency code you
  pass is not sent.
- **`inventory()->setQuantities()` no longer uses a deprecated field on API
  2026-01.** It sends `changeFromQuantity: null` there, as on newer versions,
  instead of `ignoreCompareQuantity`.

### Added

- `Configuration::SUPPORTED_API_VERSIONS` — the API versions a release is
  validated against (currently `2026-01`, `2026-04`, `2026-07`, `2026-10`).

### Internal

- The schema-validation test runs once per supported API version instead of
  only against the default, and `composer schema` downloads all of their
  schemas (`php bin/fetch-schema.php <version>…` fetches specific ones).

## [1.6.0] - 2026-11-19

### Changed

- **Queries are retried after a connection failure or timeout**, like they
  already were after a 502/503/504 (up to `maxRetries`, waiting 1s, 2s, …).
  Mutations still are not. A query that keeps timing out therefore takes up to
  `maxRetries + 1` timeouts to fail; set `maxRetries: 0` to opt out.
- **`syncMedia()` throws `InvalidArgumentException` when two slots share a
  key**, before changing anything. It used to create both images and track
  only one.
- **`products()->delete()` drops the product's media key map** from the key
  store (a `JsonFileMediaKeyStore` file, or your own store's row). If that
  fails the product is still deleted and the reason is added to `userErrors`
  with the code `MEDIA_KEY_STORE_FAILED`.
- **`translations()->register()` skips inputs whose key isn't translatable** on
  the resource and returns their keys in `skipped`, as `set()` does. They used
  to be sent without a digest, which Shopify rejects with a thrown error.
- **Large inputs are chunked** in `collections()->addProducts()` /
  `removeProducts()` (250 ids per call), `priceLists()->updatePrices()` /
  `deletePrices()` (250 per call), `translations()->register()` / `set()` (100
  per call), and media creation (250 per call). `removeProducts()` returns
  every job it started under a new `jobs` key; `job` is the last one.

### Fixed

- `files()->upload()` / `uploadMany()` / `stageJsonl()` throw `UploadException`
  when the storage host answers with a non-2xx status and the injected
  uploader has `http_errors` off; the failure used to pass as a success.
- `bulkOperations()->results()` throws `TransportException` for a non-2xx
  download and for a download that breaks part-way, instead of parsing an
  error body as rows or leaking a Guzzle exception.

### Added

- `products()->set()` and `fulfillmentOrders()->get()` select
  `pageInfo { hasNextPage }` on their `variants` (first 100) and `lineItems`
  (first 50), so a truncated list can be told from a complete one.
- `Configuration::DEFAULT_API_VERSION`, now the single place the default API
  version is defined (`ShopifyClient::DEFAULT_API_VERSION` points to it).

## [1.5.0] - 2026-11-19

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

[Unreleased]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.6.2...HEAD
[1.6.2]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.6.1...v1.6.2
[1.6.1]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.4.1...v1.5.0
[1.4.1]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/baldie81/shopify-gql-sdk/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/baldie81/shopify-gql-sdk/releases/tag/v1.0.0
