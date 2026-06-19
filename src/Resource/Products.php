<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

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
     * @param array<string,mixed> $input a ProductSetInput
     * @return array{product:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function set(array $input): array
    {
        $mutation = 'mutation ($input: ProductSetInput!) {'
            . ' productSet(input: $input) {'
            . ' product { ' . self::FIELDS . ' options { name optionValues { name } }'
            . ' variants(first: 100) { nodes { id sku price } } } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => $input])['productSet'] ?? ['product' => null, 'userErrors' => []];
    }

    /**
     * Delete a product (`productDelete`). Returns the deleted product id.
     *
     * @return array{deletedProductId:?string,userErrors:list<array<string,mixed>>}
     */
    public function delete(string $id): array
    {
        $mutation = 'mutation ($input: ProductDeleteInput!) {'
            . ' productDelete(input: $input) { deletedProductId userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => ['id' => Gid::product($id)]])['productDelete']
            ?? ['deletedProductId' => null, 'userErrors' => []];
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

        $productId = $result['product']['id'] ?? $fallbackId;
        if ($productId === null) {
            return $result; // nothing saved to translate (e.g. userErrors)
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
     * List a product's existing media nodes (`id`, `alt`, `mediaContentType`,
     * `status`).
     *
     * @return list<array<string,mixed>>
     */
    public function media(string $id, int $first = self::MAX_PAGE_SIZE): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $query = 'query ($id: ID!, $first: Int!) {'
            . ' product(id: $id) { media(first: $first) { nodes { id alt mediaContentType status } } } }';

        $data = $this->gql->execute($query, ['id' => Gid::product($id), 'first' => $first]);

        return $data['product']['media']['nodes'] ?? [];
    }

    /**
     * Attach media to a product (raw `productCreateMedia`). NOT idempotent —
     * Shopify does not dedupe by source, so repeated calls create duplicate
     * files. Use {@see ensureMedia()} for idempotent assignment.
     *
     * @param list<Media|array<string,mixed>> $media
     * @return array{media:list<array<string,mixed>>,mediaUserErrors:list<array<string,mixed>>}
     */
    public function addMedia(string $id, array $media): array
    {
        $mutation = 'mutation ($productId: ID!, $media: [CreateMediaInput!]!) {'
            . ' productCreateMedia(productId: $productId, media: $media) {'
            . ' media { id alt mediaContentType status } mediaUserErrors { field message code } } }';

        $data = $this->gql->execute($mutation, [
            'productId' => Gid::product($id),
            'media'     => $this->payloads($media),
        ]);

        return $data['productCreateMedia'] ?? ['media' => [], 'mediaUserErrors' => []];
    }

    /**
     * Idempotently assign media to a product: fetch the product's existing media,
     * skip any whose key (the `alt`) is already present, and create only the rest
     * — so re-running never adds the same file twice. The requested batch is also
     * de-duped by key. Media without a key can't be deduped and is always added.
     *
     * @param list<Media|array<string,mixed>> $media
     * @return array{added:list<array<string,mixed>>,skipped:list<string>,mediaUserErrors:list<array<string,mixed>>}
     */
    public function ensureMedia(string $id, array $media): array
    {
        $existing = [];
        foreach ($this->media($id) as $node) {
            $alt = $node['alt'] ?? null;
            if (is_string($alt) && $alt !== '') {
                $existing[$alt] = true;
            }
        }

        $toAdd   = [];
        $skipped = [];
        $seen    = [];
        foreach ($this->payloads($media) as $payload) {
            $key = (string) ($payload['alt'] ?? '');
            if ($key !== '' && (isset($existing[$key]) || isset($seen[$key]))) {
                $skipped[] = $key;
                continue;
            }
            if ($key !== '') {
                $seen[$key] = true;
            }
            $toAdd[] = $payload;
        }

        $result = ['added' => [], 'skipped' => $skipped, 'mediaUserErrors' => []];
        if ($toAdd !== []) {
            $created                   = $this->addMedia($id, $toAdd);
            $result['added']           = $created['media'] ?? [];
            $result['mediaUserErrors'] = $created['mediaUserErrors'] ?? [];
        }

        return $result;
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
        $translations = new Translations($this->gql);
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
        $data  = $this->gql->execute($query, ['q' => 'sku:' . $sku]);

        return $data['productVariants']['nodes'][0]['product']['id'] ?? null;
    }

    /**
     * Reorder a product's media to match the given id order (index 0 = first /
     * main image). `newPosition` is sent as a zero-based string, working around a
     * known Shopify quirk.
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
     * Detach media from a product (`productDeleteMedia`). Returns the deleted
     * media ids and any errors.
     *
     * @param list<string> $mediaIds
     * @return array{deletedMediaIds:list<string>,mediaUserErrors:list<array<string,mixed>>}
     */
    public function deleteMedia(string $id, array $mediaIds): array
    {
        if ($mediaIds === []) {
            return ['deletedMediaIds' => [], 'mediaUserErrors' => []];
        }

        $mutation = 'mutation ($productId: ID!, $mediaIds: [ID!]!) {'
            . ' productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {'
            . ' deletedMediaIds deletedProductImageIds mediaUserErrors { field message code } } }';

        $data = $this->gql->execute($mutation, ['productId' => Gid::product($id), 'mediaIds' => array_values($mediaIds)]);

        return $data['productDeleteMedia'] ?? ['deletedMediaIds' => [], 'mediaUserErrors' => []];
    }

    /**
     * Synchronise a product's images to the given ordered slots, idempotently:
     * for each slot, upload the local file (or use its URL), then **replace** the
     * existing media if one already carries the slot's key (its `alt`) — so a
     * re-sync never creates duplicate files — otherwise create it. Finally,
     * reorder all touched media to the slots' positions (slot position 1 = main).
     *
     * This matches the "FTP folder of `SKU_1`, `SKU_2`, …" workflow: pair it with
     * {@see \Baldie81\ShopifyGraphQL\Support\MediaFolder::scan()} and
     * {@see findIdBySku()}.
     *
     * With `$prune` true it becomes a *mirror*: existing keyed media (those with an
     * `alt`) whose key isn't in `$slots` are deleted, so the product ends up with
     * exactly the supplied set. Media without an `alt` are left untouched.
     *
     * @param list<MediaEntry> $slots
     * @return array{media:list<array{key:string,id:string,action:string}>,created:list<string>,replaced:list<string>,pruned:list<string>,orderedIds:list<string>,reorder:array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function syncMedia(string $id, array $slots, bool $prune = false): array
    {
        $gid   = Gid::product($id);
        $files = new Files($this->gql);

        $existing = [];
        foreach ($this->media($gid) as $node) {
            $alt = $node['alt'] ?? null;
            if (is_string($alt) && $alt !== '' && isset($node['id'])) {
                $existing[$alt] = (string) $node['id'];
            }
        }

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
        $replaced      = [];
        $createInputs  = [];
        $createKeys    = [];
        $action        = []; // key => 'created' | 'replaced'
        foreach ($slots as $i => $slot) {
            $key    = $slot->key();
            $source = $slot->isLocal() ? $uploaded[$i] : $slot->source();

            if (isset($existing[$key]) && $existing[$key] !== '') {
                $replaceInputs[] = ['id' => $existing[$key], 'originalSource' => $source, 'alt' => $key];
                $replaced[]      = $key;
                $action[$key]    = 'replaced';
            } else {
                $createInputs[] = ['originalSource' => $source, 'mediaContentType' => 'IMAGE', 'alt' => $key];
                $createKeys[]   = $key;
            }
        }

        $userErrors = [];

        if ($replaceInputs !== []) {
            $result = $files->update($replaceInputs);
            foreach ($result['userErrors'] ?? [] as $error) {
                $userErrors[] = $error;
            }
        }

        $created = [];
        if ($createInputs !== []) {
            $result       = $this->addMedia($gid, $createInputs);
            $createdMedia = $result['media'] ?? [];
            foreach ($result['mediaUserErrors'] ?? [] as $error) {
                $userErrors[] = $error;
            }
            // productCreateMedia returns media in input order.
            foreach ($createKeys as $index => $key) {
                $newId = $createdMedia[$index]['id'] ?? null;
                if ($newId !== null) {
                    $existing[$key] = (string) $newId;
                    $created[]      = $key;
                    $action[$key]   = 'created';
                }
            }
        }

        // Final order, in slot position order, using the (now-known) media ids.
        // `media` pairs each slot key with its media id and the action taken, so
        // the caller can persist the gid per slot.
        $orderedIds = [];
        $media      = [];
        foreach ($slots as $slot) {
            $key     = $slot->key();
            $mediaId = $existing[$key] ?? null;
            if ($mediaId !== null && $mediaId !== '') {
                $orderedIds[] = $mediaId;
                $media[]      = ['key' => $key, 'id' => $mediaId, 'action' => $action[$key] ?? 'unchanged'];
            }
        }

        $reorder = ['job' => null, 'mediaUserErrors' => []];
        if (count($orderedIds) > 1) {
            $reorder = $this->reorderMedia($gid, $orderedIds);
            foreach ($reorder['mediaUserErrors'] ?? [] as $error) {
                $userErrors[] = $error;
            }
        }

        // Mirror mode: delete keyed media that the slots didn't cover.
        $pruned = [];
        if ($prune) {
            $incoming  = [];
            foreach ($slots as $slot) {
                $incoming[$slot->key()] = true;
            }
            $orphanIds = [];
            foreach ($existing as $key => $mediaId) {
                if ($mediaId !== '' && !isset($incoming[$key])) {
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

        return [
            'media'      => $media,      // list of { key, id (gid), action } in final order
            'created'    => $created,    // keys created
            'replaced'   => $replaced,   // keys replaced
            'pruned'     => $pruned,     // media gids deleted (prune mode)
            'orderedIds' => $orderedIds, // media gids in final order
            'reorder'    => $reorder,    // { job: { id, done }, mediaUserErrors }
            'userErrors' => $userErrors, // aggregated, for logging
        ];
    }

    private static function listQuery(): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' products(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . self::FIELDS . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
