<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\MediaEntry;
use PHPUnit\Framework\TestCase;

final class MediaEntryTest extends TestCase
{
    public function testFileSlotDefaultsKeyToFilename(): void
    {
        $slot = MediaEntry::file(1, '/ftp/incoming/ACME-1_1.jpg');

        self::assertSame(1, $slot->position());
        self::assertSame('/ftp/incoming/ACME-1_1.jpg', $slot->source());
        self::assertTrue($slot->isLocal());
        self::assertSame('ACME-1_1.jpg', $slot->key());
    }

    public function testUrlSlotDerivesKeyFromUrl(): void
    {
        $slot = MediaEntry::url(2, 'https://cdn.example.com/x/back.jpg?v=9');

        self::assertFalse($slot->isLocal());
        self::assertSame('back.jpg', $slot->key());
    }

    public function testExplicitKeyOverrides(): void
    {
        self::assertSame('main', MediaEntry::file(1, '/x/a.jpg', 'main')->key());
    }
}
