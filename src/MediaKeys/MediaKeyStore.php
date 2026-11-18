<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\MediaKeys;

use Baldie81\ShopifyGraphQL\Exception\MediaKeyStoreException;

/**
 * Remembers, per product, which media a sync key belongs to — the map
 * `key => media GID` (e.g. `'ACME_1.jpg' => 'gid://shopify/MediaImage/1'`).
 *
 * {@see \Baldie81\ShopifyGraphQL\Resource\Products::syncMedia()} and
 * `ensureMedia()` use it to recognise a product's existing images by id, so a
 * re-run replaces them instead of uploading duplicates — without relying on
 * the image's alt text.
 *
 * Implement this to keep the map in your own storage (a database table, …).
 */
interface MediaKeyStore
{
    /**
     * The stored map for a product; empty when nothing is stored.
     *
     * @return array<string,string> key => media GID
     * @throws MediaKeyStoreException when the stored map can't be read
     */
    public function load(string $productGid): array;

    /**
     * Replace the stored map for a product.
     *
     * @param array<string,string> $map key => media GID
     * @throws MediaKeyStoreException when the map can't be written
     */
    public function save(string $productGid, array $map): void;

    /**
     * Drop the stored map for a product (e.g. after deleting the product).
     *
     * @throws MediaKeyStoreException
     */
    public function forget(string $productGid): void;
}
