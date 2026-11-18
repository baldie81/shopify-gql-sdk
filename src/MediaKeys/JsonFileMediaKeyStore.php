<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\MediaKeys;

use Baldie81\ShopifyGraphQL\Exception\MediaKeyStoreException;
use Baldie81\ShopifyGraphQL\Support\Gid;

/**
 * Keeps the key map in local JSON files — one small file per product, under a
 * directory you choose:
 *
 *   <directory>/<last two digits of the product id>/<product id>.json
 *
 * The two-digit subfolders keep any one directory small for large catalogues.
 * The directory (and subfolders) are created on first write. Writes go to a
 * temporary file that is then renamed into place, so a crash can't leave a
 * half-written map behind.
 *
 * The directory must persist across deploys and be backed up; it is local to
 * the machine, so several servers need shared storage. It must not be a cache
 * directory that gets cleared.
 */
final class JsonFileMediaKeyStore implements MediaKeyStore
{
    private readonly string $directory;

    public function __construct(string $directory)
    {
        if (trim($directory) === '') {
            throw new \InvalidArgumentException('The media key directory must not be empty.');
        }

        $this->directory = rtrim($directory, '/\\');
    }

    public function load(string $productGid): array
    {
        $file = $this->path($productGid);
        if (!is_file($file)) {
            return [];
        }

        $raw     = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new MediaKeyStoreException("Could not read the media key file {$file}.");
        }

        $map = [];
        foreach ($decoded as $key => $mediaId) {
            if (is_string($mediaId) && $mediaId !== '') {
                $map[(string) $key] = $mediaId;
            }
        }

        return $map;
    }

    public function save(string $productGid, array $map): void
    {
        $file = $this->path($productGid);
        $dir  = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new MediaKeyStoreException("Could not create the media key directory {$dir}.");
        }

        $json = json_encode((object) $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temp = @tempnam($dir, '.tmp');

        if ($json === false || $temp === false || @file_put_contents($temp, $json . "\n") === false || !@rename($temp, $file)) {
            if (is_string($temp)) {
                @unlink($temp);
            }

            throw new MediaKeyStoreException("Could not write the media key file {$file}.");
        }

        @chmod($file, 0664);
    }

    public function forget(string $productGid): void
    {
        $file = $this->path($productGid);
        if (is_file($file) && !@unlink($file)) {
            throw new MediaKeyStoreException("Could not delete the media key file {$file}.");
        }
    }

    /**
     * The file holding a product's map.
     */
    public function path(string $productGid): string
    {
        $id = Gid::id($productGid);
        if (preg_match('/^\d+$/', $id) !== 1) {
            $id = sha1($productGid); // never let an odd id escape the directory
        }

        $shard = str_pad(substr($id, -2), 2, '0', STR_PAD_LEFT);

        return $this->directory . DIRECTORY_SEPARATOR . $shard . DIRECTORY_SEPARATOR . $id . '.json';
    }
}
