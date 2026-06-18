<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\MediaFolder;
use PHPUnit\Framework\TestCase;

final class MediaFolderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sdk-media-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function touchFile(string $name): void
    {
        file_put_contents($this->dir . '/' . $name, 'x');
    }

    public function testGroupsBySkuOrdersByPositionAndIgnoresNonMatches(): void
    {
        // intentionally out of order, plus underscores in the SKU and noise files
        $this->touchFile('ACME-1_2.png');
        $this->touchFile('ACME-1_1.jpg');
        $this->touchFile('ACME-2_1.jpg');
        $this->touchFile('WITH_UNDER_SCORE_3.jpg'); // sku "WITH_UNDER_SCORE", position 3
        $this->touchFile('notes.txt');              // wrong extension -> ignored
        $this->touchFile('nopos.jpg');              // no trailing _N -> ignored

        $scanned = MediaFolder::scan($this->dir);

        self::assertSame(['ACME-1', 'ACME-2', 'WITH_UNDER_SCORE'], array_keys($scanned));

        $acme1 = $scanned['ACME-1'];
        self::assertCount(2, $acme1);
        self::assertSame([1, 2], [$acme1[0]->position(), $acme1[1]->position()]);
        self::assertSame(['ACME-1_1.jpg', 'ACME-1_2.png'], [$acme1[0]->key(), $acme1[1]->key()]);
        self::assertTrue($acme1[0]->isLocal());

        self::assertSame(3, $scanned['WITH_UNDER_SCORE'][0]->position());
    }
}
