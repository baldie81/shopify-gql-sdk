<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\MediaKeys;

use Baldie81\ShopifyGraphQL\Exception\MediaKeyStoreException;

/**
 * Several stores used together, in priority order — e.g. the product metafield
 * first and a local JSON file as a second copy:
 *
 *   new ChainMediaKeyStore(
 *       new MetafieldMediaKeyStore($shopify->graphql()),
 *       new JsonFileMediaKeyStore('/var/app/writable/shopify/media-keys'),
 *   );
 *
 * Reading asks every store; for each key the first store whose media id still
 * exists on the product wins, so a store that is missing or out of date is
 * covered by the next one. Writing updates every store.
 *
 * One unreadable store doesn't stop a sync as long as another can be read.
 */
final class ChainMediaKeyStore implements MediaKeyStore
{
    /** @var list<MediaKeyStore> */
    private readonly array $stores;

    public function __construct(MediaKeyStore ...$stores)
    {
        if ($stores === []) {
            throw new \InvalidArgumentException('A chain needs at least one store.');
        }

        $this->stores = array_values($stores);
    }

    /**
     * @return list<MediaKeyStore>
     */
    public function stores(): array
    {
        return $this->stores;
    }

    /**
     * The maps merged, earlier stores taking precedence.
     */
    public function load(string $productGid): array
    {
        $merged = [];
        $failed = [];
        foreach ($this->stores as $store) {
            try {
                $merged += $store->load($productGid);
            } catch (MediaKeyStoreException $e) {
                $failed[] = $e->getMessage();
            }
        }

        if (count($failed) === count($this->stores)) {
            throw new MediaKeyStoreException(implode(' | ', $failed));
        }

        return $merged;
    }

    /**
     * Writes to every store; if any fail, the rest are still written and the
     * failures are reported together afterwards.
     */
    public function save(string $productGid, array $map): void
    {
        $this->each(static fn (MediaKeyStore $store) => $store->save($productGid, $map));
    }

    public function forget(string $productGid): void
    {
        $this->each(static fn (MediaKeyStore $store) => $store->forget($productGid));
    }

    /**
     * @param callable(MediaKeyStore):void $action
     */
    private function each(callable $action): void
    {
        $failed = [];
        foreach ($this->stores as $store) {
            try {
                $action($store);
            } catch (MediaKeyStoreException $e) {
                $failed[] = $e->getMessage();
            }
        }

        if ($failed !== []) {
            throw new MediaKeyStoreException(implode(' | ', $failed));
        }
    }
}
