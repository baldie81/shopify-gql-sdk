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
 *
 * Besides the keys, the map handed to {@see save()} carries one reserved entry,
 * {@see OWNER_KEY} `=> product GID`, naming the product the map was written for.
 * Store it and return it from {@see load()} like any other entry: it is how a
 * map that was copied along with a duplicated product is told apart from the
 * product's own.
 */
interface MediaKeyStore
{
    /** Reserved map entry holding the GID of the product the map belongs to. */
    public const string OWNER_KEY = '@product';

    /**
     * The stored map for a product; empty when nothing is stored.
     *
     * @return array<string,string> key => media GID (plus the {@see OWNER_KEY} entry, when stored)
     * @throws MediaKeyStoreException when the stored map can't be read
     */
    public function load(string $productGid): array;

    /**
     * Replace the stored map for a product.
     *
     * @param array<string,string> $map key => media GID, plus the {@see OWNER_KEY} entry
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
