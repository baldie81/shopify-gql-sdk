<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Enum\MediaContentType;
use Baldie81\ShopifyGraphQL\Support\Media;
use PHPUnit\Framework\TestCase;

final class MediaTest extends TestCase
{
    public function testImageDerivesAltKeyFromUrlFilename(): void
    {
        $media = Media::image('https://cdn.example.com/x/front.jpg?v=123');

        self::assertSame('front.jpg', $media->key());
        self::assertSame([
            'originalSource'   => 'https://cdn.example.com/x/front.jpg?v=123',
            'mediaContentType' => 'IMAGE',
            'alt'              => 'front.jpg',
        ], $media->toArray());
    }

    public function testExplicitAltIsUsedAsKey(): void
    {
        $media = Media::image('https://cdn.example.com/front.jpg', alt: 'hero-shot');

        self::assertSame('hero-shot', $media->key());
        self::assertSame('hero-shot', $media->toArray()['alt']);
    }

    public function testMakeWithNonImageType(): void
    {
        $media = Media::make('https://youtu.be/abc', MediaContentType::ExternalVideo, alt: 'promo');

        self::assertSame('EXTERNAL_VIDEO', $media->toArray()['mediaContentType']);
        self::assertSame('promo', $media->key());
    }
}
