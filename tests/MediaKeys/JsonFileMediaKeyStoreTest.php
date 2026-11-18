<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\MediaKeys;

use Baldie81\ShopifyGraphQL\Exception\MediaKeyStoreException;
use Baldie81\ShopifyGraphQL\MediaKeys\JsonFileMediaKeyStore;
use PHPUnit\Framework\TestCase;

final class JsonFileMediaKeyStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sdk-json-store-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    public function testNothingStoredIsAnEmptyMapAndCreatesNothing(): void
    {
        $store = new JsonFileMediaKeyStore($this->dir);

        self::assertSame([], $store->load('gid://shopify/Product/7512345678942'));
        self::assertDirectoryDoesNotExist($this->dir); // reading never creates the folder
    }

    public function testSaveCreatesTheFolderAndOneShardedFilePerProduct(): void
    {
        $store = new JsonFileMediaKeyStore($this->dir . '/shopify/media-keys/');
        $map   = ['ACME_1.jpg' => 'gid://shopify/MediaImage/1', 'ACME_2.jpg' => 'gid://shopify/MediaImage/2'];

        $store->save('gid://shopify/Product/7512345678942', $map);
        $store->save('gid://shopify/Product/7512345678987', ['X_1.jpg' => 'gid://shopify/MediaImage/9']);

        self::assertFileExists($this->dir . '/shopify/media-keys/42/7512345678942.json');
        self::assertFileExists($this->dir . '/shopify/media-keys/87/7512345678987.json');
        self::assertSame($map, $store->load('gid://shopify/Product/7512345678942'));
        self::assertSame($map, json_decode((string) file_get_contents($this->dir . '/shopify/media-keys/42/7512345678942.json'), true));
    }

    public function testSaveReplacesTheMapAndLeavesNoTemporaryFiles(): void
    {
        $store = new JsonFileMediaKeyStore($this->dir);
        $store->save('gid://shopify/Product/5', ['a' => 'gid://shopify/MediaImage/1']);
        $store->save('gid://shopify/Product/5', ['b' => 'gid://shopify/MediaImage/2']);

        self::assertSame(['b' => 'gid://shopify/MediaImage/2'], $store->load('gid://shopify/Product/5'));
        self::assertSame(['5.json'], array_values(array_diff((array) scandir($this->dir . '/05'), ['.', '..'])));
    }

    public function testAnEmptyMapRoundTrips(): void
    {
        $store = new JsonFileMediaKeyStore($this->dir);
        $store->save('gid://shopify/Product/5', []);

        self::assertSame([], $store->load('gid://shopify/Product/5'));
    }

    public function testForgetRemovesTheFile(): void
    {
        $store = new JsonFileMediaKeyStore($this->dir);
        $store->save('gid://shopify/Product/5', ['a' => 'gid://shopify/MediaImage/1']);
        $store->forget('gid://shopify/Product/5');
        $store->forget('gid://shopify/Product/5'); // already gone: fine

        self::assertSame([], $store->load('gid://shopify/Product/5'));
    }

    public function testACorruptFileIsReportedNotTreatedAsEmpty(): void
    {
        // Treating it as "no map" would make the next sync upload everything again.
        $store = new JsonFileMediaKeyStore($this->dir);
        $store->save('gid://shopify/Product/5', ['a' => 'gid://shopify/MediaImage/1']);
        file_put_contents($store->path('gid://shopify/Product/5'), '{"a": "gid://sho');

        $this->expectException(MediaKeyStoreException::class);
        $store->load('gid://shopify/Product/5');
    }

    public function testAnOddIdCannotEscapeTheDirectory(): void
    {
        $store = new JsonFileMediaKeyStore($this->dir);
        $path  = $store->path('gid://shopify/Product/../../etc/passwd');

        self::assertStringStartsWith($this->dir . DIRECTORY_SEPARATOR, $path);
        self::assertStringNotContainsString('..', $path);
    }
}
