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
     * Normalise a list of object-or-array arguments.
     *
     * @param iterable<Arrayable|array<string,mixed>> $values
     * @return list<array<string,mixed>>
     */
    protected function payloads(iterable $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[] = $this->payload($value);
        }

        return $out;
    }

    /**
     * Set (create-or-update) metafields on any owner GID via `metafieldsSet`
     * (upsert by owner+namespace+key), chunked to Shopify's 25-per-call limit.
     * Resources expose `setMetafields($id, …)` wrappers that pass the right GID.
     *
     * @param list<\Baldie81\ShopifyGraphQL\Support\Metafield|array<string,mixed>> $metafields
     * @return array{metafields:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    protected function setMetafieldsFor(string $ownerId, array $metafields): array
    {
        $mutation = 'mutation ($metafields: [MetafieldsSetInput!]!) {'
            . ' metafieldsSet(metafields: $metafields) {'
            . ' metafields { id legacyResourceId namespace key type value createdAt updatedAt } userErrors { field message code } } }';

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
