<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

use Baldie81\ShopifyGraphQL\Enum\MediaContentType;

/**
 * A `CreateMediaInput` for attaching media to a product from a hosted source URL.
 *
 * The `alt` doubles as an **idempotency key**: Shopify's `productCreateMedia`
 * does not dedupe by source, so {@see \Baldie81\ShopifyGraphQL\Resource\Products::ensureMedia()}
 * compares this key against the product's existing media `alt` values and skips
 * anything already present. Provide a stable `alt` per asset (it defaults to the
 * source's filename) so re-runs don't create duplicate files.
 *
 *   Media::image('https://cdn.example.com/front.jpg', alt: 'front');
 *   Media::make('https://youtu.be/x', MediaContentType::ExternalVideo, alt: 'promo');
 */
final class Media implements Arrayable
{
    private function __construct(
        private readonly string $originalSource,
        private readonly string $mediaContentType,
        private readonly string $alt,
    ) {}

    public static function make(
        string $originalSource,
        MediaContentType|string $type = MediaContentType::Image,
        ?string $alt = null,
    ): self {
        return new self(
            $originalSource,
            $type instanceof MediaContentType ? $type->value : $type,
            $alt ?? self::deriveKey($originalSource),
        );
    }

    public static function image(string $url, ?string $alt = null): self
    {
        return self::make($url, MediaContentType::Image, $alt);
    }

    /**
     * The idempotency key (the `alt`); {@see Products::ensureMedia()} dedupes on it.
     */
    public function key(): string
    {
        return $this->alt;
    }

    public function toArray(): array
    {
        return [
            'originalSource'   => $this->originalSource,
            'mediaContentType' => $this->mediaContentType,
            'alt'              => $this->alt,
        ];
    }

    private static function deriveKey(string $source): string
    {
        $path = (string) (parse_url($source, PHP_URL_PATH) ?? $source);
        $base = basename($path);

        return $base !== '' ? $base : $source;
    }
}
