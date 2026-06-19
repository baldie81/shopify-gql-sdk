<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\CustomerInput;
use Baldie81\ShopifyGraphQL\Support\Gid;

/**
 * Customers — get / search / create / update, plus metafields (e.g. an ERP
 * customer id). Mirrors the Products read/write shape.
 */
final class Customers extends AbstractResource
{
    private const FIELDS = 'id legacyResourceId email firstName lastName phone numberOfOrders createdAt updatedAt';

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        $query = 'query ($id: ID!) { customer(id: $id) { ' . self::FIELDS . ' } }';

        return $this->gql->execute($query, ['id' => Gid::customer($id)])['customer'] ?? null;
    }

    /**
     * One page of a customer search (`$query` is Shopify syntax, e.g.
     * `email:jane@example.com` or `tag:vip`).
     *
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function search(string $query = '', int $first = 50, ?string $after = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery(), ['first' => $first, 'after' => $after, 'query' => $query]);

        return $data['customers'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchAll(string $query = '', int $pageSize = self::MAX_PAGE_SIZE): array
    {
        return $this->paginateNodes(self::listQuery(), ['query' => $query], 'customers', $pageSize);
    }

    /**
     * Create a customer (`customerCreate`). Accepts a {@see CustomerInput} or raw array.
     *
     * @param CustomerInput|array<string,mixed> $input
     * @return array{customer:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function create(CustomerInput|array $input): array
    {
        $mutation = 'mutation ($input: CustomerInput!) {'
            . ' customerCreate(input: $input) { customer { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => $this->payload($input)])['customerCreate'] ?? ['customer' => null, 'userErrors' => []];
    }

    /**
     * Update a customer (`customerUpdate`); the id is injected into the input.
     *
     * @param CustomerInput|array<string,mixed> $input
     * @return array{customer:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function update(string $id, CustomerInput|array $input): array
    {
        $mutation = 'mutation ($input: CustomerInput!) {'
            . ' customerUpdate(input: $input) { customer { ' . self::FIELDS . ' } userErrors { field message } } }';

        $payload = ['id' => Gid::customer($id)] + $this->payload($input);

        return $this->gql->execute($mutation, ['input' => $payload])['customerUpdate'] ?? ['customer' => null, 'userErrors' => []];
    }

    /**
     * Set metafields on a customer (e.g. an ERP reference).
     *
     * @param list<\Baldie81\ShopifyGraphQL\Support\Metafield|array<string,mixed>> $metafields
     * @return array{metafields:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function setMetafields(string $id, array $metafields): array
    {
        return $this->setMetafieldsFor(Gid::customer($id), $metafields);
    }

    private static function listQuery(): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' customers(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . self::FIELDS . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
