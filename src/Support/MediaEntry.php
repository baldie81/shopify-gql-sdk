<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * One desired media image at a given 1-based position on a product, for
 * {@see \Baldie81\ShopifyGraphQL\Resource\Products::syncMedia()}.
 *
 * The source is either a local file (uploaded via staged uploads) or an already
 * hosted URL. The `key` is the idempotency key (stored as the media `alt`): on a
 * re-sync, a slot whose key already exists is replaced in place rather than
 * duplicated. It defaults to the filename, which suits the `SKU_1.jpg` / `SKU_2.jpg`
 * convention.
 *
 *   MediaEntry::file(1, '/ftp/ACME-1_1.jpg');   // main image, uploaded
 *   MediaEntry::url(2, 'https://cdn/x/back.jpg'); // already hosted
 */
final class MediaEntry
{
    private function __construct(
        private readonly int $position,
        private readonly string $source,
        private readonly bool $local,
        private readonly string $key,
    ) {}

    public static function file(int $position, string $path, ?string $key = null): self
    {
        return new self($position, $path, true, $key ?? basename($path));
    }

    public static function url(int $position, string $url, ?string $key = null): self
    {
        return new self($position, $url, false, $key ?? self::deriveKey($url));
    }

    public function position(): int
    {
        return $this->position;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function isLocal(): bool
    {
        return $this->local;
    }

    public function key(): string
    {
        return $this->key;
    }

    private static function deriveKey(string $url): string
    {
        $base = basename((string) (parse_url($url, PHP_URL_PATH) ?? $url));

        return $base !== '' ? $base : $url;
    }
}
