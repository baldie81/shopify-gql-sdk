<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL;

/**
 * Connection settings for the Shopify Admin GraphQL API.
 *
 *   new Configuration(
 *       shop:        'my-store',          // or 'my-store.myshopify.com'
 *       accessToken: 'shpat_...',         // Admin API access token
 *       apiVersion:  '2026-10',           // dated, quarterly; ~12-month support window
 *   );
 */
final class Configuration
{
    /** The Admin API version used when none is given. */
    public const string DEFAULT_API_VERSION = '2026-10';

    private readonly string $host;

    /**
     * @param int $maxRetries how many times a throttled (or, for queries, a
     *                        transiently failing) request is retried
     *
     * @throws \InvalidArgumentException if `$shop` isn't a shop handle or hostname
     */
    public function __construct(
        public readonly string $shop,
        #[\SensitiveParameter]
        public readonly string $accessToken,
        public readonly string $apiVersion = self::DEFAULT_API_VERSION,
        public readonly float $timeout = 30.0,
        public readonly float $connectTimeout = 10.0,
        public readonly bool $verifySsl = true,
        public readonly int $maxRetries = 2,
    ) {
        $this->host = self::resolveHost($shop);

        if (preg_match('/^(\d{4}-\d{2}|unstable)$/', $apiVersion) !== 1) {
            throw new \InvalidArgumentException("Invalid API version \"{$apiVersion}\" (expected YYYY-MM or \"unstable\").");
        }
    }

    /**
     * The versioned Admin API root, used as the HTTP client's base URI.
     * The single GraphQL endpoint (`graphql.json`) is resolved against it.
     */
    public function baseUri(): string
    {
        return "https://{$this->host}/admin/api/{$this->apiVersion}/";
    }

    /**
     * The full GraphQL endpoint, e.g.
     * `https://my-store.myshopify.com/admin/api/2026-10/graphql.json`.
     */
    public function endpoint(): string
    {
        return $this->baseUri() . 'graphql.json';
    }

    /**
     * Accepts a bare handle (`my-store`), a hostname, or a pasted URL — scheme,
     * path and query are dropped. Anything that isn't a hostname is rejected
     * rather than silently producing a broken endpoint.
     */
    private static function resolveHost(string $shop): string
    {
        $host = (string) preg_replace('#^https?://#i', '', trim($shop));
        $host = strtolower((string) preg_replace('#[/?\#].*$#', '', $host));

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $host) !== 1) {
            throw new \InvalidArgumentException("Invalid shop \"{$shop}\" (expected a handle like \"my-store\" or a hostname).");
        }

        return str_contains($host, '.') ? $host : "{$host}.myshopify.com";
    }
}
