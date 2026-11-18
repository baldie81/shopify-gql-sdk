<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\MediaKeys\ChainMediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\JsonFileMediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\MediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\MetafieldMediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\NullMediaKeyStore;
use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\MediaEntry;
use Baldie81\ShopifyGraphQL\Support\MediaFolder;

/**
 * Media key stores — where `syncMedia()` / `ensureMedia()` remember which image
 * on a product belongs to which key (`ACME_1.jpg` → media id), so a re-sync
 * replaces images instead of duplicating them.
 *
 *   - default()                      — nothing to set up: a metafield on each product
 *   - metafieldThenJsonFile($dir)    — metafield first, a local JSON file as a second copy
 *   - altTextOnly()                  — opt out: match by alt text, as before 1.4
 *   - syncFolder($dir, $sku)         — a sync that sets real alt text
 *   - forgetProduct($productId)      — drop a product's stored map
 *
 * In a CodeIgniter 4 app, wire the client once as a service and keep the JSON
 * copy under `writable/` (never under `writable/cache/`, which gets cleared):
 *
 *   // app/Config/Services.php
 *   public static function shopify(bool $getShared = true): ShopifyClient
 *   {
 *       if ($getShared) {
 *           return static::getSharedInstance('shopify');
 *       }
 *
 *       $shopify = ShopifyClient::create(new Configuration(env('shopify.shop'), env('shopify.token')));
 *
 *       return $shopify->useMediaKeyStore(new ChainMediaKeyStore(
 *           new MetafieldMediaKeyStore($shopify->graphql()),
 *           new JsonFileMediaKeyStore(WRITEPATH . 'shopify/media-keys'), // created on first write
 *       ));
 *   }
 */
final class MediaKeyStoreExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * No configuration: each product carries its map in the
     * `shopify_gql_sdk.media_keys` metafield.
     */
    public function default(): ShopifyClient
    {
        return $this->shopify;
    }

    /**
     * Metafield first, then a local JSON file (one file per product; the
     * directory is created if it doesn't exist). For each key, the first store
     * holding a media id that is still on the product wins; if neither has a
     * usable entry, existing media is adopted by its alt text once.
     */
    public function metafieldThenJsonFile(string $directory): ShopifyClient
    {
        return $this->shopify->useMediaKeyStore(new ChainMediaKeyStore(
            new MetafieldMediaKeyStore($this->shopify->graphql()),
            new JsonFileMediaKeyStore($directory),
        ));
    }

    /**
     * Keep no map at all and match media by alt text only.
     */
    public function altTextOnly(): ShopifyClient
    {
        return $this->shopify->useMediaKeyStore(new NullMediaKeyStore());
    }

    /**
     * Sync a folder of `SKU_1.jpg`, `SKU_2.jpg`, … for one SKU, giving each
     * image a real description. The filename stays the key.
     *
     * @return array<string,mixed>|null the syncMedia() result, or null if the SKU is unknown
     */
    public function syncFolder(string $directory, string $sku, string $productTitle): ?array
    {
        $productId = $this->shopify->products()->findIdBySku($sku);
        if ($productId === null) {
            return null;
        }

        $slots = [];
        foreach (MediaFolder::scan($directory)[$sku] ?? [] as $entry) {
            $slots[] = MediaEntry::file(
                $entry->position(),
                $entry->source(),
                key: $entry->key(),
                alt: "{$productTitle}, image {$entry->position()}",
            );
        }

        $result = $this->shopify->products()->syncMedia($productId, $slots, prune: true);

        // $result['keyStoreSaved'] === false means the media changed but the map
        // couldn't be written; the reason is in $result['userErrors']. Sync again.
        return $result;
    }

    /**
     * Drop a product's stored map (e.g. after deleting the product).
     */
    public function forgetProduct(MediaKeyStore $store, string $productGid): void
    {
        $store->forget($productGid);
    }
}
