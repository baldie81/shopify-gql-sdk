<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Exception\MediaKeyStoreException;
use Baldie81\ShopifyGraphQL\GraphQL\Client;
use Baldie81\ShopifyGraphQL\MediaKeys\ChainMediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\MediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\MetafieldMediaKeyStore;
use Baldie81\ShopifyGraphQL\Support\Arrayable;
use Baldie81\ShopifyGraphQL\Support\Gid;
use Baldie81\ShopifyGraphQL\Support\Media;
use Baldie81\ShopifyGraphQL\Support\MediaEntry;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use Baldie81\ShopifyGraphQL\Support\ProductField;
use Baldie81\ShopifyGraphQL\Support\ProductInput;

/**
 * Products: the `product`/`products` queries and the `productCreate` /
 * `productUpdate` mutations.
 *
 * Reads use a default selection set ({@see FIELDS}); mutations return the
 * `{ product, userErrors }` payload unchanged so callers can inspect
 * `userErrors` (Shopify's business-validation results, returned as data rather
 * than thrown).
 *
 * @link https://shopify.dev/docs/api/admin-graphql/latest/objects/Product Shopify Admin GraphQL: Product
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/productSet Shopify Admin GraphQL: productSet
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/productCreate Shopify Admin GraphQL: productCreate
 */
final class Products extends AbstractResource
{
    /**
     * The default fields fetched for a product. Kept lean on purpose — Shopify
     * bills by query cost, so over-fetching has a real price.
     */
    private const string FIELDS = <<<'GQL'
        id
        legacyResourceId
        title
        handle
        status
        vendor
        productType
        tags
        createdAt
        updatedAt
        GQL;

    private const string MEDIA_FIELDS = 'id alt mediaContentType status';

    private readonly Files $files;

    private readonly Translations $translations;

    private readonly MediaKeyStore $mediaKeys;

    /**
     * @param MediaKeyStore|null $mediaKeys where the `key => media id` maps used by
     *                                      {@see syncMedia()} / {@see ensureMedia()} are
     *                                      kept; defaults to a metafield on the product
     */
    public function __construct(Client $gql, ?Files $files = null, ?Translations $translations = null, ?MediaKeyStore $mediaKeys = null)
    {
        parent::__construct($gql);
        $this->files        = $files ?? new Files($gql);
        $this->translations = $translations ?? new Translations($gql);
        $this->mediaKeys    = $mediaKeys ?? new MetafieldMediaKeyStore($gql);
    }

    /**
     * Fetch a single product by id (numeric id or a GID). Returns the product
     * node, or null if it doesn't exist.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $id, ?string $fields = null): ?array
    {
        $query = 'query ($id: ID!) { product(id: $id) { ' . ($fields ?? self::FIELDS) . ' } }';
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
    public function search(string $query = '', int $first = 50, ?string $after = null, ?string $fields = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery($fields), [
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
    public function searchAll(string $query = '', int $pageSize = self::MAX_PAGE_SIZE, ?string $fields = null): array
    {
        return $this->paginateNodes(self::listQuery($fields), ['query' => $query], 'products', $pageSize);
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

        $data   = $this->gql->execute($mutation, ['product' => $this->payload($input)]);
        $result = $data['productCreate'] ?? ['product' => null, 'userErrors' => []];

        return $this->applyAttachedTranslations($input, $result);
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
        $result  = $data['productUpdate'] ?? ['product' => null, 'userErrors' => []];

        return $this->applyAttachedTranslations($input, $result, Gid::product($id));
    }

    /**
     * Declaratively create or update a product from a full `ProductSetInput` —
     * the way to create a product *with options and variants* in one call.
     * Pass the raw input array (options as `productOptions`, variants with
     * `optionValues`/`price`/`inventoryItem.sku`). Returns `{ product, userErrors }`.
     *
     * The returned product lists its first 100 variants; when
     * `variants.pageInfo.hasNextPage` is true there are more — read them with
     * {@see Variants::list()}.
     *
     * @param array<string,mixed> $input a ProductSetInput
     * @return array{product:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function set(array $input): array
    {
        $mutation = 'mutation ($input: ProductSetInput!) {'
            . ' productSet(input: $input) {'
            . ' product { ' . self::FIELDS . ' options { name optionValues { name } }'
            . ' variants(first: 100) { nodes { id sku price } pageInfo { hasNextPage } } } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => $input])['productSet'] ?? ['product' => null, 'userErrors' => []];
    }

    /**
     * Delete a product (`productDelete`). Returns the deleted product id.
     *
     * The product's media key map is dropped from the key store as well (the
     * default metafield store needs nothing: the metafield goes with the
     * product). If that fails the product is still deleted — `deletedProductId`
     * is set — and the reason is added to `userErrors` with the code
     * `MEDIA_KEY_STORE_FAILED`.
     *
     * @return array{deletedProductId:?string,userErrors:list<array<string,mixed>>}
     */
    public function delete(string $id): array
    {
        $mutation = 'mutation ($input: ProductDeleteInput!) {'
            . ' productDelete(input: $input) { deletedProductId userErrors { field message } } }';

        $gid    = Gid::product($id);
        $result = $this->gql->execute($mutation, ['input' => ['id' => $gid]])['productDelete'] ?? [];
        $result = ['deletedProductId' => $result['deletedProductId'] ?? null, 'userErrors' => $result['userErrors'] ?? []];

        if ($result['deletedProductId'] !== null) {
            foreach ($this->keyStores() as $store) {
                if ($store instanceof MetafieldMediaKeyStore) {
                    continue; // deleted along with the product
                }

                try {
                    $store->forget($gid);
                } catch (MediaKeyStoreException $e) {
                    $result['userErrors'][] = ['field' => null, 'message' => $e->getMessage(), 'code' => 'MEDIA_KEY_STORE_FAILED'];
                }
            }
        }

        return $result;
    }

    /**
     * If the input carried translatable {@see ProductField}s, register their
     * translations against the saved product and attach the result. Translations
     * can't ride in the create/update mutation itself, so this is the
     * second hop — done for you so a localized create reads as one call.
     *
     * @param ProductInput|array<string,mixed> $input
     * @param array<string,mixed>              $result
     * @return array<string,mixed>
     */
    private function applyAttachedTranslations(ProductInput|array $input, array $result, ?string $fallbackId = null): array
    {
        if (!$input instanceof ProductInput) {
            return $result;
        }

        $fields = $input->translatableFields();
        if ($fields === []) {
            return $result;
        }

        // Nothing was saved (userErrors), so there's nothing to translate —
        // registering against the unchanged product would be a half-applied write.
        if (($result['userErrors'] ?? []) !== []) {
            return $result;
        }

        $productId = $result['product']['id'] ?? $fallbackId;
        if ($productId === null) {
            return $result;
        }

        $result['translations'] = $this->setTranslations((string) $productId, $fields);

        return $result;
    }

    /**
     * Set (create-or-update) metafields on a product. `metafieldsSet` upserts by
     * (owner, namespace, key), so this is naturally idempotent. Accepts
     * {@see Metafield} builders or raw arrays;
     * the product `ownerId` is injected. Chunked to Shopify's 25-per-call limit.
     *
     * @param list<Metafield|array<string,mixed>> $metafields
     * @return array{metafields:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function setMetafields(string $id, array $metafields): array
    {
        return $this->setMetafieldsFor(Gid::product($id), $metafields);
    }

    /**
     * List a product's media nodes (`id`, `alt`, `mediaContentType`, `status`),
     * in position order. Walks every page; pass `$first` to cap the count.
     *
     * @return list<array<string,mixed>>
     */
    public function media(string $id, ?int $first = null): array
    {
        $query = 'query ($id: ID!, $first: Int!, $after: String) {'
            . ' product(id: $id) { media(first: $first, after: $after) {'
            . ' nodes { ' . self::MEDIA_FIELDS . ' } pageInfo { hasNextPage endCursor } } } }';

        return $this->paginateNodes($query, ['id' => Gid::product($id)], ['product', 'media'], limit: $first);
    }

    /**
     * Attach media to a product. NOT idempotent — Shopify does not dedupe by
     * source, so repeated calls create duplicate files. Use {@see ensureMedia()}
     * for idempotent assignment.
     *
     * Sent as `productUpdate(media: …)` (`productCreateMedia` is deprecated).
     * Returns the newly created media nodes; `mediaUserErrors` is kept as an
     * alias of `userErrors` for backward compatibility.
     *
     * @param list<Media|array<string,mixed>> $media
     * @return array{media:list<array<string,mixed>>,userErrors:list<array<string,mixed>>,mediaUserErrors:list<array<string,mixed>>}
     */
    public function addMedia(string $id, array $media): array
    {
        $inputs = $this->payloads($media);
        if ($inputs === []) {
            return ['media' => [], 'userErrors' => [], 'mediaUserErrors' => []];
        }

        return $this->createMedia(Gid::product($id), $inputs, array_column($this->media($id), 'id'));
    }

    /**
     * Create media via `productUpdate` and work out which nodes are new: the
     * payload returns the product's media (newest first), and anything whose id
     * wasn't in `$knownIds` was created by this call.
     *
     * @param list<array<string,mixed>> $inputs   CreateMediaInput payloads
     * @param list<string>              $knownIds media ids on the product before the call
     * @return array{media:list<array<string,mixed>>,userErrors:list<array<string,mixed>>,mediaUserErrors:list<array<string,mixed>>}
     */
    private function createMedia(string $gid, array $inputs, array $knownIds): array
    {
        $mutation = 'mutation ($product: ProductUpdateInput!, $media: [CreateMediaInput!], $first: Int!) {'
            . ' productUpdate(product: $product, media: $media) {'
            . ' product { id media(first: $first, sortKey: ID, reverse: true) { nodes { ' . self::MEDIA_FIELDS . ' } } }'
            . ' userErrors { field message } } }';

        $known      = array_flip($knownIds);
        $created    = [];
        $userErrors = [];

        // A page of the payload must be able to hold everything one call created.
        foreach (array_chunk($inputs, self::MAX_PAGE_SIZE) as $chunk) {
            $result = $this->gql->execute($mutation, [
                'product' => ['id' => $gid],
                'media'   => $chunk,
                'first'   => count($chunk),
            ])['productUpdate'] ?? [];

            $new = [];
            foreach ($result['product']['media']['nodes'] ?? [] as $node) {
                if (isset($node['id']) && !isset($known[$node['id']])) {
                    $new[]              = $node;
                    $known[$node['id']] = true;
                }
            }
            foreach (array_reverse($new) as $node) { // newest-first -> creation (input) order
                $created[] = $node;
            }
            foreach ($result['userErrors'] ?? [] as $error) {
                $userErrors[] = $error;
            }
        }

        return ['media' => $created, 'userErrors' => $userErrors, 'mediaUserErrors' => $userErrors];
    }

    /**
     * Idempotently assign media to a product: skip any whose key is already on
     * the product and create only the rest — so re-running never adds the same
     * file twice. The requested batch is also de-duped by key. Media without a
     * key can't be deduped and is always added.
     *
     * Existing media is recognised through the {@see MediaKeyStore} (see
     * {@see syncMedia()} for how keys are matched).
     *
     * @param list<Media|Arrayable|array<string,mixed>> $media
     * @return array{added:list<array<string,mixed>>,skipped:list<string>,mediaUserErrors:list<array<string,mixed>>,keyStoreSaved:bool}
     */
    public function ensureMedia(string $id, array $media): array
    {
        $gid   = Gid::product($id);
        $state = $this->resolveMediaKeys($gid);
        $known = $state['known'];

        $toAdd   = [];
        $addKeys = [];
        $skipped = [];
        $seen    = [];
        foreach ($media as $item) {
            if ($item instanceof Media) {
                $key     = $item->key();
                $payload = $item->toArray();
            } else {
                $payload = $this->payload($item);
                // A raw array may name its key explicitly; it is not part of CreateMediaInput.
                $key = (string) ($payload['key'] ?? $payload['alt'] ?? '');
                unset($payload['key']);
            }

            if ($key !== '' && (isset($known[$key]) || isset($seen[$key]))) {
                $skipped[] = $key;
                continue;
            }
            if ($key !== '') {
                $seen[$key] = true;
            }
            $toAdd[]   = $payload;
            $addKeys[] = $key;
        }

        $result = ['added' => [], 'skipped' => $skipped, 'mediaUserErrors' => [], 'keyStoreSaved' => true];
        if ($toAdd !== []) {
            $created                   = $this->createMedia($gid, $toAdd, $state['ids']);
            $result['added']           = $created['media'];
            $result['mediaUserErrors'] = $created['userErrors'];

            foreach (self::pairCreated($toAdd, $created['media']) as $index => $mediaId) {
                if ($addKeys[$index] !== '') {
                    $known[$addKeys[$index]] = $mediaId;
                }
            }
        }

        $error = $this->saveMediaKeys($gid, $known, $state['loaded']);
        if ($error !== null) {
            $result['keyStoreSaved']     = false;
            $result['mediaUserErrors'][] = $error;
        }

        return $result;
    }

    /**
     * Work out which key belongs to which media currently on the product.
     *
     * The stored key maps are the rule: a key maps to the media id recorded for
     * it, provided that media is still on the product (with several stores, the
     * first one holding a still-valid id for a key wins). Alt text is ignored.
     *
     * A stored map names the product it was written for (the
     * {@see MediaKeyStore::OWNER_KEY} entry). A map naming *another* product was
     * copied along with a duplicated product and is ignored.
     *
     * Only when the product has no map of its own and no stored entry is usable
     * — a product synced before key maps existed, or a duplicated/migrated
     * product — is the media *adopted* by alt text, once: every media with a
     * non-empty alt becomes `alt => id`. Saving the result afterwards means the
     * next sync is back to matching by id. A product that does have its own map
     * is never adopted from, even when every entry in it has gone stale, so
     * images added outside the SDK stay untracked.
     *
     * @return array{ids:list<string>,known:array<string,string>,adopted:list<string>,loaded:list<array<string,string>|null>}
     * @throws MediaKeyStoreException when none of the stores can be read
     */
    private function resolveMediaKeys(string $gid): array
    {
        $stores = $this->keyStores();

        // The metafield store's map can be read in the media query itself.
        $inline = null;
        foreach ($stores as $store) {
            if ($store instanceof MetafieldMediaKeyStore) {
                $inline = $store;
                break;
            }
        }

        [$nodes, $product] = $this->readMedia($gid, $inline);

        $loaded   = [];
        $failures = [];
        foreach ($stores as $store) {
            try {
                $loaded[] = $store === $inline ? $inline->parse($product) : $store->load($gid);
            } catch (MediaKeyStoreException $e) {
                $loaded[]   = null;
                $failures[] = $e->getMessage();
            }
        }
        // One unreadable store is covered by the others; with none readable we
        // can't tell what's already there, so stop before changing anything.
        if ($failures !== [] && count($failures) === count($stores)) {
            throw new MediaKeyStoreException(implode(' | ', $failures));
        }

        $ids = [];
        foreach ($nodes as $node) {
            if (isset($node['id'])) {
                $ids[] = (string) $node['id'];
            }
        }
        $onProduct = array_flip($ids);

        $known = [];
        $owned = false; // does any store hold a map written for this very product?
        foreach ($loaded as $map) {
            $owner = $map[MediaKeyStore::OWNER_KEY] ?? null;
            if ($owner !== null && $owner !== $gid) {
                continue; // another product's map, copied when the product was duplicated
            }
            $owned = $owned || $owner === $gid;

            foreach ($map ?? [] as $key => $mediaId) {
                if ((string) $key !== MediaKeyStore::OWNER_KEY && isset($onProduct[$mediaId]) && !isset($known[$key])) {
                    $known[(string) $key] = $mediaId;
                }
            }
        }

        $adopted = [];
        if ($known === [] && !$owned) {
            foreach ($nodes as $node) {
                $alt = $node['alt'] ?? null;
                if (is_string($alt) && $alt !== '' && $alt !== MediaKeyStore::OWNER_KEY && isset($node['id']) && !isset($known[$alt])) {
                    $known[$alt] = (string) $node['id'];
                    $adopted[]   = $alt;
                }
            }
        }

        return ['ids' => $ids, 'known' => $known, 'adopted' => $adopted, 'loaded' => $loaded];
    }

    /**
     * Persist the key map — stamped with the product it belongs to — to every
     * store whose copy differs. Returns null on
     * success, or a userError-shaped entry describing the failure — the media
     * itself has already been changed by then, so this is reported, not thrown.
     *
     * @param array<string,string>             $map
     * @param list<array<string,string>|null>  $loaded what each store returned when read
     * @return array{field:null,message:string,code:string}|null
     */
    private function saveMediaKeys(string $gid, array $map, array $loaded): ?array
    {
        $map[MediaKeyStore::OWNER_KEY] = $gid;
        ksort($map);

        $failures = [];
        foreach ($this->keyStores() as $index => $store) {
            $current = $loaded[$index] ?? null;
            if ($current !== null) {
                ksort($current);
                if ($current === $map) {
                    continue; // already up to date: no write
                }
            }

            try {
                $store->save($gid, $map);
            } catch (MediaKeyStoreException $e) {
                $failures[] = $e->getMessage();
            }
        }

        return $failures === []
            ? null
            : ['field' => null, 'message' => implode(' | ', $failures), 'code' => 'MEDIA_KEY_STORE_FAILED'];
    }

    /**
     * @return list<MediaKeyStore>
     */
    private function keyStores(): array
    {
        return $this->mediaKeys instanceof ChainMediaKeyStore ? $this->mediaKeys->stores() : [$this->mediaKeys];
    }

    /**
     * Every media node on the product plus, when `$inline` is given, the product
     * node carrying that store's map — read in the same request.
     *
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>|null}
     */
    private function readMedia(string $gid, ?MetafieldMediaKeyStore $inline): array
    {
        $query = 'query ($id: ID!, $first: Int!, $after: String) {'
            . ' product(id: $id) { ' . ($inline !== null ? $inline->selection() . ' ' : '')
            . 'media(first: $first, after: $after) {'
            . ' nodes { ' . self::MEDIA_FIELDS . ' } pageInfo { hasNextPage endCursor } } } }';

        $nodes   = [];
        $product = null;
        $after   = null;

        do {
            $page = $this->gql->execute($query, ['id' => $gid, 'first' => self::MAX_PAGE_SIZE, 'after' => $after])['product'] ?? null;
            if (!is_array($page)) {
                break;
            }
            $product ??= $page;

            foreach ($page['media']['nodes'] ?? [] as $node) {
                $nodes[] = $node;
            }

            $pageInfo = $page['media']['pageInfo'] ?? [];
            $after    = $pageInfo['endCursor'] ?? null;
        } while (($pageInfo['hasNextPage'] ?? false) && $after !== null);

        return [$nodes, $product];
    }

    /**
     * Match the media a create call returned to the inputs that produced them,
     * as `input index => media id`.
     *
     * An input whose alt text is unique in the batch is matched by that alt, so
     * a partial failure can't shift ids onto the wrong inputs. Anything not
     * matched that way (the same description on two images, or alt text Shopify
     * normalised) is matched by position — but only when every input was
     * created, so nothing can have shifted.
     *
     * @param list<array<string,mixed>> $inputs  CreateMediaInput payloads
     * @param list<array<string,mixed>> $created media nodes, in creation order
     * @return array<int,string>
     */
    private static function pairCreated(array $inputs, array $created): array
    {
        $altCounts = array_count_values(array_map(static fn (array $input): string => (string) ($input['alt'] ?? ''), $inputs));

        $idByAlt = [];
        foreach ($created as $node) {
            $alt = (string) ($node['alt'] ?? '');
            if (isset($node['id']) && ($altCounts[$alt] ?? 0) === 1 && $alt !== '') {
                $idByAlt[$alt] ??= (string) $node['id'];
            }
        }

        // By alt first; whatever is left falls back to position, which is only
        // trusted when every input was created (nothing can have shifted).
        $allCreated = count($created) === count($inputs);
        $paired     = [];
        foreach ($inputs as $index => $input) {
            $alt = (string) ($input['alt'] ?? '');
            if (isset($idByAlt[$alt])) {
                $paired[$index] = $idByAlt[$alt];
            }
        }
        if ($allCreated) {
            $taken = array_flip($paired);
            foreach ($inputs as $index => $input) {
                $mediaId = $created[$index]['id'] ?? null;
                if (!isset($paired[$index]) && $mediaId !== null && !isset($taken[(string) $mediaId])) {
                    $paired[$index] = (string) $mediaId;
                }
            }
        }
        ksort($paired);

        return $paired;
    }

    /**
     * Translate product fields fluently, one or many locales at once:
     *
     *   $products->setTranslations($id, [
     *       ProductField::title()->withTranslation('fr', 'Robe'),
     *       ProductField::description()->withTranslation('fr', '<p>…</p>')->withTranslation('es', '<p>…</p>'),
     *   ]);
     *
     * The required source digests are looked up automatically and one
     * `translationsRegister` call covers every field/locale. Fields that aren't
     * translatable on the product (e.g. uncustomised SEO) come back under
     * `skipped` rather than erroring.
     *
     * @param list<ProductField> $fields
     * @return array{translations:list<array<string,mixed>>,userErrors:list<array<string,mixed>>,digests:array<string,string>,skipped:list<string>}
     */
    public function setTranslations(string $id, array $fields, ?string $marketId = null): array
    {
        $gid          = Gid::product($id);
        $translations = $this->translations;
        $digests      = $translations->digests($gid);

        $inputs  = [];
        $skipped = [];
        foreach ($fields as $field) {
            $key = $field->key();
            if (!isset($digests[$key])) {
                if ($field->translations() !== []) {
                    $skipped[] = $key;
                }
                continue;
            }
            foreach ($field->translations() as $translation) {
                $input = [
                    'locale'                    => $translation['locale'],
                    'key'                       => $key,
                    'value'                     => $translation['value'],
                    'translatableContentDigest' => $digests[$key],
                ];
                if ($marketId !== null) {
                    $input['marketId'] = $marketId;
                }
                $inputs[] = $input;
            }
        }

        $result = $inputs === []
            ? ['translations' => [], 'userErrors' => [], 'digests' => []]
            : $translations->register($gid, $inputs);
        $result['skipped'] = array_values(array_unique($skipped));

        return $result;
    }

    /**
     * Resolve a product id from one of its variant SKUs (null if none match).
     */
    public function findIdBySku(string $sku): ?string
    {
        $query = 'query ($q: String!) { productVariants(first: 1, query: $q) { nodes { product { id } } } }';
        // Quoted, so SKUs containing spaces, colons or quotes match exactly
        // instead of being parsed as search syntax.
        $data  = $this->gql->execute($query, ['q' => 'sku:"' . addcslashes($sku, '"\\') . '"']);

        return $data['productVariants']['nodes'][0]['product']['id'] ?? null;
    }

    /**
     * Reorder a product's media to match the given id order (index 0 = first /
     * main image). `newPosition` is zero-based and sent as a string, as its
     * `UnsignedInt64` type requires.
     *
     * @param list<string> $orderedMediaIds
     * @return array{job:?array<string,mixed>,mediaUserErrors:list<array<string,mixed>>}
     */
    public function reorderMedia(string $id, array $orderedMediaIds): array
    {
        $moves = [];
        foreach (array_values($orderedMediaIds) as $index => $mediaId) {
            $moves[] = ['id' => $mediaId, 'newPosition' => (string) $index];
        }

        $mutation = 'mutation ($id: ID!, $moves: [MoveInput!]!) {'
            . ' productReorderMedia(id: $id, moves: $moves) {'
            . ' job { id done } mediaUserErrors { field message } } }';

        $data = $this->gql->execute($mutation, ['id' => Gid::product($id), 'moves' => $moves]);

        return $data['productReorderMedia'] ?? ['job' => null, 'mediaUserErrors' => []];
    }

    /**
     * Remove media from a product. By default the media is *detached*
     * (`fileUpdate` with `referencesToRemove`; `productDeleteMedia` is
     * deprecated): the file stays in the store's Files library and on any other
     * product that uses it. Pass `$deleteFiles: true` to delete the files
     * outright (`fileDelete`), which removes them everywhere.
     *
     * @param list<string> $mediaIds
     * @return array{deletedMediaIds:list<string>,mediaUserErrors:list<array<string,mixed>>}
     */
    public function deleteMedia(string $id, array $mediaIds, bool $deleteFiles = false): array
    {
        $mediaIds = array_values($mediaIds);
        if ($mediaIds === []) {
            return ['deletedMediaIds' => [], 'mediaUserErrors' => []];
        }

        if ($deleteFiles) {
            $deleted = $this->files->delete($mediaIds);

            return ['deletedMediaIds' => $deleted['deletedFileIds'], 'mediaUserErrors' => $deleted['userErrors']];
        }

        $gid     = Gid::product($id);
        $updated = $this->files->update(array_map(
            static fn (string $mediaId): array => ['id' => $mediaId, 'referencesToRemove' => [$gid]],
            $mediaIds,
        ));

        return [
            'deletedMediaIds' => array_values(array_column($updated['files'] ?? [], 'id')),
            'mediaUserErrors' => $updated['userErrors'] ?? [],
        ];
    }

    /**
     * Synchronise a product's images to the given ordered slots, idempotently:
     * for each slot, upload the local file (or use its URL), then **replace** the
     * existing media if the slot's key is already on the product — so a re-sync
     * never creates duplicate files — otherwise create it. Finally, reorder all
     * touched media to the slots' positions (slot position 1 = main).
     *
     * This matches the "FTP folder of `SKU_1`, `SKU_2`, …" workflow: pair it with
     * {@see \Baldie81\ShopifyGraphQL\Support\MediaFolder::scan()} and
     * {@see findIdBySku()}.
     *
     * **How keys are matched.** The product's `key => media id` map is kept in a
     * {@see MediaKeyStore} (by default a metafield on the product) and media is
     * recognised by id, so alt text is free for real descriptions and editing
     * it in the admin doesn't cause a duplicate. Media synced before key maps
     * existed is adopted by its alt text once (listed under `adopted`).
     *
     * **Alt text.** A slot's `alt` is used when given. Otherwise a new image gets
     * its key as alt text and a replaced image keeps the alt text it has.
     *
     * With `$prune` true it becomes a *mirror*: media this SDK tracks whose key
     * isn't in `$slots` is removed from the product, so the product ends up with
     * exactly the supplied set. Untracked media is left untouched — except on a
     * product's very first sync, where media adopted by alt text counts as tracked.
     *
     * A replace Shopify rejects is reported in `userErrors`; that key is left
     * out of `replaced` and its `media` entry has the action `unchanged`.
     *
     * If the key map can't be saved after the media was changed, the call still
     * returns: `keyStoreSaved` is false and the reason is in `userErrors`.
     * Persist `media` and sync again to repair it.
     *
     * @param list<MediaEntry> $slots
     * @return array{media:list<array{key:string,id:string,action:string}>,created:list<string>,replaced:list<string>,pruned:list<string>,adopted:list<string>,orderedIds:list<string>,reorder:array<string,mixed>,keyStoreSaved:bool,userErrors:list<array<string,mixed>>}
     * @throws MediaKeyStoreException    when no key store can be read (nothing is changed)
     * @throws \InvalidArgumentException when two slots share a key (nothing is changed)
     */
    public function syncMedia(string $id, array $slots, bool $prune = false): array
    {
        $gid   = Gid::product($id);
        $files = $this->files;

        // A key identifies one image: two slots with the same key would create
        // two images and track only one of them.
        $counts     = array_count_values(array_map(static fn (MediaEntry $slot): string => $slot->key(), $slots));
        $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
        if ($duplicates !== []) {
            throw new \InvalidArgumentException('Each media slot needs its own key; used more than once: ' . implode(', ', $duplicates) . '.');
        }

        // Read first: if the key stores are unreadable we stop before uploading.
        $state    = $this->resolveMediaKeys($gid);
        $existing = $state['known'];
        $knownIds = $state['ids'];

        usort($slots, static fn (MediaEntry $a, MediaEntry $b): int => $a->position() <=> $b->position());

        // Resolve every source up front: one stagedUploadsCreate covers all local
        // files (their bytes are still pushed individually).
        $localPaths = [];
        foreach ($slots as $i => $slot) {
            if ($slot->isLocal()) {
                $localPaths[$i] = $slot->source();
            }
        }
        $uploaded = $localPaths === []
            ? []
            : array_combine(array_keys($localPaths), $files->uploadMany(array_values($localPaths)));

        // Partition into one batch of replaces and one batch of creates.
        $replaceInputs = [];
        $replaceKeys   = [];
        $createInputs  = [];
        $createKeys    = [];
        $action        = []; // key => 'created' | 'replaced'
        foreach ($slots as $i => $slot) {
            $key    = $slot->key();
            $source = $slot->isLocal() ? $uploaded[$i] : $slot->source();

            if (isset($existing[$key])) {
                $input = ['id' => $existing[$key], 'originalSource' => $source];
                if ($slot->alt() !== null) {
                    $input['alt'] = $slot->alt(); // otherwise the image keeps its alt text
                }
                $replaceInputs[] = $input;
                $replaceKeys[]   = $key;
            } else {
                $createInputs[] = ['originalSource' => $source, 'mediaContentType' => 'IMAGE', 'alt' => $slot->alt() ?? $key];
                $createKeys[]   = $key;
            }
        }

        $userErrors = [];

        $replaced = [];
        if ($replaceInputs !== []) {
            $result = $files->update($replaceInputs);
            $errors = $result['userErrors'] ?? [];
            foreach ($errors as $error) {
                $userErrors[] = $error;
            }

            // Without errors every file was replaced. With errors, only the files
            // Shopify returned as updated were — the rest are left as they are.
            $updated = array_flip(array_column($result['files'] ?? [], 'id'));
            foreach ($replaceKeys as $index => $key) {
                if ($errors === [] || isset($updated[$replaceInputs[$index]['id']])) {
                    $replaced[]   = $key;
                    $action[$key] = 'replaced';
                }
            }
        }

        $created = [];
        if ($createInputs !== []) {
            $result = $this->createMedia($gid, $createInputs, $knownIds);
            foreach ($result['userErrors'] as $error) {
                $userErrors[] = $error;
            }
            foreach (self::pairCreated($createInputs, $result['media']) as $index => $mediaId) {
                $key            = $createKeys[$index];
                $existing[$key] = $mediaId;
                $created[]      = $key;
                $action[$key]   = 'created';
            }
        }

        // Final order, in slot position order, using the (now-known) media ids.
        // `media` pairs each slot key with its media id and the action taken, so
        // the caller can persist the gid per slot.
        $orderedIds = [];
        $media      = [];
        foreach ($slots as $slot) {
            $key = $slot->key();
            if (isset($existing[$key])) {
                $orderedIds[] = $existing[$key];
                $media[]      = ['key' => $key, 'id' => $existing[$key], 'action' => $action[$key] ?? 'unchanged'];
            }
        }

        $reorder = ['job' => null, 'mediaUserErrors' => []];
        if (count($orderedIds) > 1) {
            $reorder = $this->reorderMedia($gid, $orderedIds);
            foreach ($reorder['mediaUserErrors'] ?? [] as $error) {
                $userErrors[] = $error;
            }
        }

        // Mirror mode: remove tracked media that the slots didn't cover.
        $pruned = [];
        if ($prune) {
            $incoming = [];
            foreach ($slots as $slot) {
                $incoming[$slot->key()] = true;
            }
            $orphanIds = [];
            foreach ($existing as $key => $mediaId) {
                if (!isset($incoming[$key])) {
                    $orphanIds[] = $mediaId;
                }
            }
            if ($orphanIds !== []) {
                $deleted = $this->deleteMedia($gid, $orphanIds);
                $pruned  = $deleted['deletedMediaIds'] ?? [];
                foreach ($deleted['mediaUserErrors'] ?? [] as $error) {
                    $userErrors[] = $error;
                }
            }
        }

        // Remember what is on the product now (minus anything just removed).
        $map   = array_diff($existing, $pruned);
        $error = $this->saveMediaKeys($gid, $map, $state['loaded']);
        if ($error !== null) {
            $userErrors[] = $error;
        }

        return [
            'media'         => $media,              // list of { key, id (gid), action } in final order
            'created'       => $created,            // keys created
            'replaced'      => $replaced,           // keys replaced
            'pruned'        => $pruned,             // media gids removed (prune mode)
            'adopted'       => $state['adopted'],   // keys matched by alt text (one-time migration)
            'orderedIds'    => $orderedIds,         // media gids in final order
            'reorder'       => $reorder,            // { job: { id, done }, mediaUserErrors }
            'keyStoreSaved' => $error === null,     // false: the key map could not be written
            'userErrors'    => $userErrors,         // aggregated, for logging
        ];
    }

    private static function listQuery(?string $fields = null): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' products(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . ($fields ?? self::FIELDS) . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
