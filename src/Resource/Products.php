<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\Gid;
use Baldie81\ShopifyGraphQL\Support\Media;
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

        $data = $this->gql->execute($mutation, ['product' => $this->payload($input)]);

        return $data['productCreate'] ?? ['product' => null, 'userErrors' => []];
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

        return $data['productUpdate'] ?? ['product' => null, 'userErrors' => []];
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
        $ownerId  = Gid::product($id);
        $mutation = 'mutation ($metafields: [MetafieldsSetInput!]!) {'
            . ' metafieldsSet(metafields: $metafields) {'
            . ' metafields { id namespace key type value } userErrors { field message code } } }';

        $inputs = [];
        foreach ($this->payloads($metafields) as $metafield) {
            $inputs[] = ['ownerId' => $ownerId] + $metafield;
        }

        $merged = ['metafields' => [], 'userErrors' => []];
        foreach (array_chunk($inputs, 25) as $chunk) {
            $result = $this->gql->execute($mutation, ['metafields' => $chunk])['metafieldsSet'] ?? [];
            foreach ($result['metafields'] ?? [] as $metafield) {
                $merged['metafields'][] = $metafield;
            }
            foreach ($result['userErrors'] ?? [] as $error) {
                $merged['userErrors'][] = $error;
            }
        }

        return $merged;
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
     * @return array{translations:list<array<string,mixed>>,userErrors:list<array<string,mixed>>,skipped:list<string>}
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

        $result            = $inputs === [] ? ['translations' => [], 'userErrors' => []] : $translations->register($gid, $inputs);
        $result['skipped'] = array_values(array_unique($skipped));

        return $result;
    }

    private static function listQuery(): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' products(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . self::FIELDS . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
