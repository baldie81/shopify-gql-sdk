<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

use Baldie81\ShopifyGraphQL\Enum\MediaContentType;

/**
 * A `CreateMediaInput` for attaching media to a product from a hosted source URL.
 *
 * Each media has a **key** — a stable name for the asset, by default the
 * source's filename. Shopify does not dedupe by source, so
 * {@see \Baldie81\ShopifyGraphQL\Resource\Products::ensureMedia()} uses the key
 * to recognise media that is already on the product and skip it.
 *
 * The `alt` is the image's alt text. Pass a real description together with a
 * `key`; if only `alt` is given it doubles as the key (as in earlier versions),
 * and if neither is given both are the filename.
 *
 *   Media::image('https://cdn.example.com/front.jpg');                                  // key & alt: front.jpg
 *   Media::image('https://cdn.example.com/front.jpg', alt: 'Red tee, front', key: 'front');
 *   Media::make('https://youtu.be/x', MediaContentType::ExternalVideo, key: 'promo');
 */
final class Media implements Arrayable
{
    private function __construct(
        private readonly string $originalSource,
        private readonly string $mediaContentType,
        private readonly string $alt,
        private readonly string $key,
    ) {}

    public static function make(
        string $originalSource,
        MediaContentType|string $type = MediaContentType::Image,
        ?string $alt = null,
        ?string $key = null,
    ): self {
        $key ??= $alt ?? self::deriveKey($originalSource);

        return new self(
            $originalSource,
            $type instanceof MediaContentType ? $type->value : $type,
            $alt ?? $key,
            $key,
        );
    }

    public static function image(string $url, ?string $alt = null, ?string $key = null): self
    {
        return self::make($url, MediaContentType::Image, $alt, $key);
    }

    /**
     * The idempotency key; {@see \Baldie81\ShopifyGraphQL\Resource\Products::ensureMedia()} dedupes on it.
     */
    public function key(): string
    {
        return $this->key;
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
