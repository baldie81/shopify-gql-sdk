<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * FILES — push local files to Shopify (staged uploads) and update a file in
 * place. Reached via `$shopify->files()`.
 *
 *   $ex = new FileExamples(ExampleClient::create());
 *
 * Operations shown in this file:
 *   - upload()     — upload one local file; returns its resource URL
 *   - uploadMany() — upload several; returns a path => URL map
 *   - updateAlt()  — fileUpdate: change a file's alt text (or replace its source)
 *
 * Notes:
 *   - A staged upload is two steps under the hood (reserve a target, PUT the
 *     bytes); `upload()` does both and hands back a URL usable as a media source.
 *   - Attaching images to a product (and idempotent media sync / reorder / prune)
 *     lives on the Products resource, not here.
 *   - `stageJsonl()` (used by bulk imports) is also on this resource.
 *
 * Related: ProductExamples (product media), BulkOperationExamples.
 */
final class FileExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Upload one local file; returns its resource URL (usable as a media source).
     */
    public function upload(string $path): string
    {
        return $this->shopify->files()->upload($path);
    }

    /**
     * Upload several files at once.
     *
     * @param list<string> $paths
     * @return array<string,string> path => resource URL
     */
    public function uploadMany(array $paths): array
    {
        return $this->shopify->files()->uploadMany($paths);
    }

    /**
     * Update a file in place — e.g. change its alt text (`fileUpdate`).
     */
    public function updateAlt(string $fileId, string $alt): array
    {
        return $this->shopify->files()->update([
            ['id' => $fileId, 'alt' => $alt],
        ]);
    }
}
