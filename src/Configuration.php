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
    public function __construct(
        public readonly string $shop,
        public readonly string $accessToken,
        public readonly string $apiVersion = '2026-10',
        public readonly float $timeout = 30.0,
        public readonly float $connectTimeout = 10.0,
        public readonly bool $verifySsl = true,
    ) {}

    /**
     * The versioned Admin API root, used as the HTTP client's base URI.
     * The single GraphQL endpoint (`graphql.json`) is resolved against it.
     */
    public function baseUri(): string
    {
        return "https://{$this->host()}/admin/api/{$this->apiVersion}/";
    }

    /**
     * The full GraphQL endpoint, e.g.
     * `https://my-store.myshopify.com/admin/api/2026-10/graphql.json`.
     */
    public function endpoint(): string
    {
        return $this->baseUri() . 'graphql.json';
    }

    private function host(): string
    {
        $shop = (string) preg_replace('#^https?://#', '', trim($this->shop));
        $shop = rtrim($shop, '/');

        return str_contains($shop, '.') ? $shop : "{$shop}.myshopify.com";
    }
}
