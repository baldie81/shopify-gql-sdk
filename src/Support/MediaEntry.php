<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * One desired media image at a given 1-based position on a product, for
 * {@see \Baldie81\ShopifyGraphQL\Resource\Products::syncMedia()}.
 *
 * The source is either a local file (uploaded via staged uploads) or an already
 * hosted URL. The `key` is the idempotency key: on a re-sync, a slot whose key
 * is already on the product is replaced in place rather than duplicated. It
 * defaults to the filename, which suits the `SKU_1.jpg` / `SKU_2.jpg` convention.
 *
 * `alt` is the image's alt text. Leave it out and a new image gets its key as
 * alt text, while an existing image keeps whatever alt text it has.
 *
 *   MediaEntry::file(1, '/ftp/ACME-1_1.jpg');                       // main image, uploaded
 *   MediaEntry::url(2, 'https://cdn/x/back.jpg');                   // already hosted
 *   MediaEntry::file(1, '/ftp/ACME-1_1.jpg', alt: 'Red tee, front'); // with real alt text
 */
final class MediaEntry
{
    private function __construct(
        private readonly int $position,
        private readonly string $source,
        private readonly bool $local,
        private readonly string $key,
        private readonly ?string $alt = null,
    ) {}

    public static function file(int $position, string $path, ?string $key = null, ?string $alt = null): self
    {
        return new self($position, $path, true, $key ?? basename($path), $alt);
    }

    public static function url(int $position, string $url, ?string $key = null, ?string $alt = null): self
    {
        return new self($position, $url, false, $key ?? self::deriveKey($url), $alt);
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

    /**
     * The alt text to set, or null to leave it to the default (see the class docs).
     */
    public function alt(): ?string
    {
        return $this->alt;
    }

    /**
     * The source's filename. A URL whose last path segment isn't a filename
     * (no extension) is told apart by its query string, so
     * `…/watch?v=AAA` and `…/watch?v=BBB` don't share the key `watch`.
     */
    private static function deriveKey(string $url): string
    {
        $base = basename((string) (parse_url($url, PHP_URL_PATH) ?? $url));
        if ($base === '') {
            return $url;
        }

        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');

        return $query !== '' && !str_contains($base, '.') ? "{$base}?{$query}" : $base;
    }
}
