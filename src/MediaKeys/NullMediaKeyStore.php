<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\MediaKeys;

/**
 * Stores nothing. With this store media is always matched by its alt text —
 * the behaviour of versions before 1.4. Use it to opt out of key maps.
 */
final class NullMediaKeyStore implements MediaKeyStore
{
    public function load(string $productGid): array
    {
        return [];
    }

    public function save(string $productGid, array $map): void
    {
    }

    public function forget(string $productGid): void
    {
    }
}
