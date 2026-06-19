<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\CollectionInput;
use Baldie81\ShopifyGraphQL\Support\Gid;

/**
 * Collections — manual and smart (rule-based). Create/update/delete, search,
 * manage product membership, and metafields.
 *
 * Note Shopify's asymmetry: `addProducts` is synchronous, while
 * `removeProducts` and `reorderProducts` run as async jobs (poll the returned
 * job's `done` via {@see Collections::job()}).
 */
final class Collections extends AbstractResource
{
    private const FIELDS = 'id legacyResourceId title handle sortOrder updatedAt';

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        $query = 'query ($id: ID!) { collection(id: $id) { ' . self::FIELDS . ' } }';

        return $this->gql->execute($query, ['id' => Gid::collection($id)])['collection'] ?? null;
    }

    /**
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function search(string $query = '', int $first = 50, ?string $after = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery(), ['first' => $first, 'after' => $after, 'query' => $query]);

        return $data['collections'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchAll(string $query = '', int $pageSize = self::MAX_PAGE_SIZE): array
    {
        return $this->paginateNodes(self::listQuery(), ['query' => $query], 'collections', $pageSize);
    }

    /**
     * @param CollectionInput|array<string,mixed> $input
     * @return array{collection:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function create(CollectionInput|array $input): array
    {
        $mutation = 'mutation ($input: CollectionInput!) {'
            . ' collectionCreate(input: $input) { collection { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => $this->payload($input)])['collectionCreate'] ?? ['collection' => null, 'userErrors' => []];
    }

    /**
     * @param CollectionInput|array<string,mixed> $input
     * @return array{collection:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function update(string $id, CollectionInput|array $input): array
    {
        $mutation = 'mutation ($input: CollectionInput!) {'
            . ' collectionUpdate(input: $input) { collection { ' . self::FIELDS . ' } userErrors { field message } } }';

        $payload = ['id' => Gid::collection($id)] + $this->payload($input);

        return $this->gql->execute($mutation, ['input' => $payload])['collectionUpdate'] ?? ['collection' => null, 'userErrors' => []];
    }

    /**
     * @return array{deletedCollectionId:?string,userErrors:list<array<string,mixed>>}
     */
    public function delete(string $id): array
    {
        $mutation = 'mutation ($input: CollectionDeleteInput!) {'
            . ' collectionDelete(input: $input) { deletedCollectionId userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => ['id' => Gid::collection($id)]])['collectionDelete'] ?? ['deletedCollectionId' => null, 'userErrors' => []];
    }

    /**
     * Add products to a manual collection (synchronous).
     *
     * @param list<string> $productIds
     * @return array{collection:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function addProducts(string $id, array $productIds): array
    {
        $mutation = 'mutation ($id: ID!, $productIds: [ID!]!) {'
            . ' collectionAddProducts(id: $id, productIds: $productIds) { collection { id } userErrors { field message } } }';

        return $this->gql->execute($mutation, [
            'id'         => Gid::collection($id),
            'productIds' => array_values(array_map(static fn (string $p): string => Gid::product($p), $productIds)),
        ])['collectionAddProducts'] ?? ['collection' => null, 'userErrors' => []];
    }

    /**
     * Remove products from a manual collection (asynchronous — returns a job).
     *
     * @param list<string> $productIds
     * @return array{job:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function removeProducts(string $id, array $productIds): array
    {
        $mutation = 'mutation ($id: ID!, $productIds: [ID!]!) {'
            . ' collectionRemoveProducts(id: $id, productIds: $productIds) { job { id done } userErrors { field message } } }';

        return $this->gql->execute($mutation, [
            'id'         => Gid::collection($id),
            'productIds' => array_values(array_map(static fn (string $p): string => Gid::product($p), $productIds)),
        ])['collectionRemoveProducts'] ?? ['job' => null, 'userErrors' => []];
    }

    /**
     * Reorder products in a manual collection (asynchronous — returns a job).
     * Each move is `['id' => productGid, 'newPosition' => 0]` (zero-based).
     *
     * @param list<array<string,mixed>> $moves
     * @return array{job:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function reorderProducts(string $id, array $moves): array
    {
        $mutation = 'mutation ($id: ID!, $moves: [MoveInput!]!) {'
            . ' collectionReorderProducts(id: $id, moves: $moves) { job { id done } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::collection($id), 'moves' => array_values($moves)])['collectionReorderProducts'] ?? ['job' => null, 'userErrors' => []];
    }

    /**
     * The products in a collection.
     *
     * @return list<array<string,mixed>>
     */
    public function products(string $id, int $first = self::MAX_PAGE_SIZE): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $query = 'query ($id: ID!, $first: Int!) { collection(id: $id) { products(first: $first) { nodes { id title handle } } } }';

        return $this->gql->execute($query, ['id' => Gid::collection($id), 'first' => $first])['collection']['products']['nodes'] ?? [];
    }

    /**
     * @param list<\Baldie81\ShopifyGraphQL\Support\Metafield|array<string,mixed>> $metafields
     * @return array{metafields:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function setMetafields(string $id, array $metafields): array
    {
        return $this->setMetafieldsFor(Gid::collection($id), $metafields);
    }

    /**
     * Poll an async job (e.g. from {@see removeProducts()}/{@see reorderProducts()}).
     *
     * @return array{id:string,done:bool}|null
     */
    public function job(string $id): ?array
    {
        return $this->gql->execute('query ($id: ID!) { job(id: $id) { id done } }', ['id' => $id])['job'] ?? null;
    }

    private static function listQuery(): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' collections(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . self::FIELDS . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
