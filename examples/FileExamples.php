<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * Files — push local files to Shopify via staged uploads, and update a file in
 * place. Product media attaches uploaded images to products (see Products).
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
