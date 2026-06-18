<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\GraphQL\Client;
use Baldie81\ShopifyGraphQL\Support\Arrayable;

abstract class AbstractResource
{
    /**
     * Shopify caps connection page sizes at 250; asking for more is rejected, so
     * requests are capped here.
     */
    protected const int MAX_PAGE_SIZE = 250;

    public function __construct(
        protected readonly Client $gql,
    ) {}

    /**
     * Normalize an object-or-array argument to the variables' payload.
     *
     * @param Arrayable|array<string,mixed> $value
     * @return array<string,mixed>
     */
    protected function payload(Arrayable|array $value): array
    {
        return $value instanceof Arrayable ? $value->toArray() : $value;
    }

    /**
     * Walk a top-level Relay connection by cursor and return the merged `nodes`.
     *
     * The query must accept `$first: Int!` and `$after: String` and select
     * `nodes { … } pageInfo { hasNextPage endCursor }` on `$connectionKey`.
     * Caller-supplied `$variables` (e.g. a search `query`) are merged in; the
     * cursor variables always win.
     *
     * @param array<string,mixed> $variables
     * @return list<array<string,mixed>>
     */
    protected function paginateNodes(
        string $query,
        array $variables,
        string $connectionKey,
        int $pageSize = self::MAX_PAGE_SIZE,
    ): array {
        $pageSize = max(1, min($pageSize, self::MAX_PAGE_SIZE));

        $nodes = [];
        $after = null;

        do {
            $data = $this->gql->execute($query, ['first' => $pageSize, 'after' => $after] + $variables);
            $conn = $data[$connectionKey] ?? [];

            foreach ($conn['nodes'] ?? [] as $node) {
                $nodes[] = $node;
            }

            $pageInfo = $conn['pageInfo'] ?? [];
            $hasNext  = (bool) ($pageInfo['hasNextPage'] ?? false);
            $after    = $pageInfo['endCursor'] ?? null;
        } while ($hasNext && $after !== null);

        return $nodes;
    }
}
