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
final class Configuration implements \JsonSerializable
{
    /** The Admin API version used when none is given. */
    public const string DEFAULT_API_VERSION = '2026-10';

    /**
     * The API versions this release is validated against: every operation the
     * SDK sends is checked against each one's schema. They are the versions
     * Shopify serves at the time of release (a version lasts about a year).
     * Other versions are accepted, but nothing checks them.
     *
     * @var list<string>
     */
    public const array SUPPORTED_API_VERSIONS = ['2026-01', '2026-04', '2026-07', self::DEFAULT_API_VERSION];

    private readonly string $host;

    /**
     * @param int  $maxRetries      how many times a throttled (or, for queries, a
     *                              transiently failing) request is retried
     * @param bool $allowCustomHost accept a `$shop` host outside `myshopify.com`
     *                              (a proxy, a mock server). Off by default, so a
     *                              shop name taken from user input can't direct the
     *                              access token to another host. Never turn it on
     *                              for a value you don't control.
     *
     * @throws \InvalidArgumentException if `$shop` isn't a shop handle or a
     *                                   `*.myshopify.com` hostname
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
        public readonly bool $allowCustomHost = false,
    ) {
        $this->host = self::resolveHost($shop, $allowCustomHost);

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
     * rather than silently producing a broken endpoint, and so is a hostname
     * outside `myshopify.com` unless custom hosts were allowed.
     */
    private static function resolveHost(string $shop, bool $allowCustomHost): string
    {
        $host = (string) preg_replace('#^https?://#i', '', trim($shop));
        $host = strtolower((string) preg_replace('#[/?\#].*$#', '', $host));

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $host) !== 1) {
            throw new \InvalidArgumentException("Invalid shop \"{$shop}\" (expected a handle like \"my-store\" or a hostname).");
        }

        if (!str_contains($host, '.')) {
            return "{$host}.myshopify.com";
        }

        if (!$allowCustomHost && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.myshopify\.com$/', $host) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid shop \"{$shop}\": not a myshopify.com host. Pass allowCustomHost: true to use another host.",
            );
        }

        return $host;
    }

    /**
     * The settings without the access token, for `var_dump()` / `print_r()`.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '***'] + get_object_vars($this);
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * The access token must not end up in a cache or session by accident.
     *
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('Configuration holds the access token and cannot be serialized.');
    }
}
