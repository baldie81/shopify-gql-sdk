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
 * Real-life usage: a write method takes **one media record** as `$data` (a local
 * path / alt text coming from your DAM/ERP, defaulting to {@see SAMPLE}). Typical
 * loop:
 *
 *   foreach ($erp->productImages() as $image) {
 *       $url = $ex->upload($image);   // then attach $url as product media
 *   }
 *
 * Operations shown in this file:
 *   - upload($data)            — upload one local file; returns its resource URL
 *   - uploadMany($data)        — upload several; returns a path => URL map
 *   - updateAlt($fileId, $data)— fileUpdate: change a file's alt text
 *
 * Notes:
 *   - A staged upload is two steps under the hood (reserve a target, PUT the
 *     bytes); `upload()` does both and hands back a URL usable as a media source.
 *   - Attaching images to a product (idempotent media sync / reorder / prune) is
 *     on the Products resource, not here. `stageJsonl()` (bulk imports) is here too.
 *
 * Related: ProductExamples (product media), BulkOperationExamples.
 */
final class FileExamples
{
    /** A media record, as it might come from your DAM/ERP. */
    private const SAMPLE = [
        'path'  => '/path/to/SKU_1.jpg',
        'paths' => ['/path/to/SKU_1.jpg', '/path/to/SKU_2.jpg'],
        'alt'   => 'Front view',
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Upload one local file; returns its resource URL (usable as a media source).
     *
     * @param array<string,mixed> $data a media record (uses its `path`)
     */
    public function upload(array $data = self::SAMPLE): string
    {
        return $this->shopify->files()->upload($data['path']);
    }

    /**
     * Upload several files at once.
     *
     * @param array<string,mixed> $data a media record (uses its `paths`)
     * @return array<string,string> path => resource URL
     */
    public function uploadMany(array $data = self::SAMPLE): array
    {
        return $this->shopify->files()->uploadMany($data['paths']);
    }

    /**
     * Update a file in place — e.g. change its alt text (`fileUpdate`).
     *
     * @param array<string,mixed> $data a media record (uses its `alt`)
     */
    public function updateAlt(string $fileId, array $data = self::SAMPLE): array
    {
        return $this->shopify->files()->update([
            ['id' => $fileId, 'alt' => $data['alt']],
        ]);
    }
}
