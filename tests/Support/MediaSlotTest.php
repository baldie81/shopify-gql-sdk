<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\MediaSlot;
use PHPUnit\Framework\TestCase;

final class MediaSlotTest extends TestCase
{
    public function testFileSlotDefaultsKeyToFilename(): void
    {
        $slot = MediaSlot::file(1, '/ftp/incoming/ACME-1_1.jpg');

        self::assertSame(1, $slot->position());
        self::assertSame('/ftp/incoming/ACME-1_1.jpg', $slot->source());
        self::assertTrue($slot->isLocal());
        self::assertSame('ACME-1_1.jpg', $slot->key());
    }

    public function testUrlSlotDerivesKeyFromUrl(): void
    {
        $slot = MediaSlot::url(2, 'https://cdn.example.com/x/back.jpg?v=9');

        self::assertFalse($slot->isLocal());
        self::assertSame('back.jpg', $slot->key());
    }

    public function testExplicitKeyOverrides(): void
    {
        self::assertSame('main', MediaSlot::file(1, '/x/a.jpg', 'main')->key());
    }
}
