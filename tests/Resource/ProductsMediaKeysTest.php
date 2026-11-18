<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Exception\MediaKeyStoreException;
use Baldie81\ShopifyGraphQL\MediaKeys\ChainMediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\JsonFileMediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\MediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\MetafieldMediaKeyStore;
use Baldie81\ShopifyGraphQL\Support\Media;
use Baldie81\ShopifyGraphQL\Support\MediaEntry;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

/**
 * syncMedia()/ensureMedia() with a key store: media is recognised by the stored
 * `key => media id` map, not by alt text.
 */
final class ProductsMediaKeysTest extends MockClientTestCase
{
    private const string PRODUCT = 'gid://shopify/Product/55';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sdk-media-keys-' . bin2hex(random_bytes(6));
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

    /**
     * The media-read response: the product's media plus (optionally) the stored map.
     *
     * @param list<array<string,mixed>>  $nodes
     * @param array<string,string>|null  $map
     */
    private static function media(array $nodes, ?array $map = null): Response
    {
        return new Response(200, [], (string) json_encode(['data' => ['product' => [
            'mediaKeys' => $map === null ? null : ['value' => json_encode((object) $map)],
            'media'     => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]]]));
    }

    /**
     * @param list<array<string,mixed>> $newestFirst
     */
    private static function created(array $newestFirst): Response
    {
        return new Response(200, [], (string) json_encode(['data' => ['productUpdate' => [
            'product' => ['id' => self::PRODUCT, 'media' => ['nodes' => $newestFirst]], 'userErrors' => [],
        ]]]));
    }

    private static function replaced(): Response
    {
        return new Response(200, [], (string) json_encode(['data' => ['fileUpdate' => ['files' => [], 'userErrors' => []]]]));
    }

    private static function saved(): Response
    {
        return new Response(200, [], (string) json_encode(['data' => ['metafieldsSet' => ['metafields' => [['id' => 'gid://shopify/Metafield/1']], 'userErrors' => []]]]));
    }

    /**
     * @return array<string,string> the map written by the metafieldsSet request at $index
     */
    private function savedMap(int $index): array
    {
        $body = $this->body($index);
        self::assertStringContainsString('metafieldsSet', $body['query']);
        $input = $body['variables']['metafields'][0];
        self::assertSame(self::PRODUCT, $input['ownerId']);
        self::assertSame('shopify_gql_sdk', $input['namespace']);
        self::assertSame('media_keys', $input['key']);
        self::assertSame('json', $input['type']);

        return json_decode($input['value'], true);
    }

    public function testTheMapIsReadInTheSameRequestAsTheMedia(): void
    {
        $client = $this->mockClient([self::media([], []), self::created([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'A_1.jpg']]), self::saved()]);

        $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        $query = $this->body(0)['query'];
        self::assertStringContainsString('mediaKeys: metafield(namespace: "shopify_gql_sdk", key: "media_keys") { value }', $query);
        self::assertStringContainsString('media(first: $first, after: $after)', $query);
        self::assertCount(3, $this->history); // read (map + media), create, save map — no separate map lookup
    }

    public function testFirstSyncCreatesAndRecordsTheMap(): void
    {
        $client = $this->mockClient([
            self::media([]),
            self::created([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'A_1.jpg']]),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame(['A_1.jpg'], $result['created']);
        self::assertSame([], $result['adopted']);
        self::assertTrue($result['keyStoreSaved']);
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $this->savedMap(2));
    }

    public function testMediaIsRecognisedByIdEvenAfterItsAltTextWasEdited(): void
    {
        // Someone replaced the alt text in the admin. Matching by alt would upload a duplicate.
        $client = $this->mockClient([
            self::media(
                [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'Red tee, front view']],
                ['A_1.jpg' => 'gid://shopify/MediaImage/1'],
            ),
            self::replaced(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame(['A_1.jpg'], $result['replaced']);
        self::assertSame([], $result['created']);

        // Replaced in place, and the edited alt text is left alone.
        self::assertSame(
            [['id' => 'gid://shopify/MediaImage/1', 'originalSource' => 'https://cdn.test/A_1.jpg']],
            $this->body(1)['variables']['files'],
        );
        self::assertCount(2, $this->history); // map unchanged: nothing to save
    }

    public function testAltTextIsIgnoredOnceAMapExists(): void
    {
        // Media/2's alt happens to equal the key, but the map says the key is Media/1.
        $client = $this->mockClient([
            self::media(
                [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'something else'], ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'A_1.jpg']],
                ['A_1.jpg' => 'gid://shopify/MediaImage/1'],
            ),
            self::replaced(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame('gid://shopify/MediaImage/1', $result['media'][0]['id']);
        self::assertSame([], $result['adopted']);
    }

    public function testMediaSyncedBeforeKeyMapsExistedIsAdoptedByAltOnce(): void
    {
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'A_1.jpg']]), // no map yet
            self::replaced(),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        // Not re-uploaded: adopted by its alt text, and the map is written so
        // the next sync matches by id.
        self::assertSame(['A_1.jpg'], $result['adopted']);
        self::assertSame(['A_1.jpg'], $result['replaced']);
        self::assertSame([], $result['created']);
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $this->savedMap(2));
    }

    public function testAStaleMapFromADuplicatedProductFallsBackToAdoption(): void
    {
        // The map was copied with the product; its ids belong to the original.
        $client = $this->mockClient([
            self::media(
                [['id' => 'gid://shopify/MediaImage/900', 'alt' => 'A_1.jpg']],
                ['A_1.jpg' => 'gid://shopify/MediaImage/1'],
            ),
            self::replaced(),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame(['A_1.jpg'], $result['adopted']);
        self::assertSame('gid://shopify/MediaImage/900', $this->body(1)['variables']['files'][0]['id']); // never the stale id
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/900'], $this->savedMap(2));
    }

    public function testEntriesForMediaNoLongerOnTheProductAreDropped(): void
    {
        $client = $this->mockClient([
            self::media(
                [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'x']],
                ['A_1.jpg' => 'gid://shopify/MediaImage/1', 'A_2.jpg' => 'gid://shopify/MediaImage/GONE'],
            ),
            self::replaced(),
            self::saved(),
        ]);

        $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $this->savedMap(2));
    }

    public function testCustomAltTextIsSentOnCreateAndOnReplace(): void
    {
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'old']], ['A_1.jpg' => 'gid://shopify/MediaImage/1']),
            self::replaced(),
            self::created([['id' => 'gid://shopify/MediaImage/2', 'alt' => 'Red tee, back']]),
            new Response(200, [], (string) json_encode(['data' => ['productReorderMedia' => ['job' => null, 'mediaUserErrors' => []]]])),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [
            MediaEntry::url(1, 'https://cdn.test/A_1.jpg', alt: 'Red tee, front'),
            MediaEntry::url(2, 'https://cdn.test/A_2.jpg', alt: 'Red tee, back'),
        ]);

        self::assertSame('Red tee, front', $this->body(1)['variables']['files'][0]['alt']);
        self::assertSame('Red tee, back', $this->body(2)['variables']['media'][0]['alt']);
        // The key, not the alt text, identifies the new media.
        self::assertSame(
            ['A_1.jpg' => 'gid://shopify/MediaImage/1', 'A_2.jpg' => 'gid://shopify/MediaImage/2'],
            $this->savedMap(4),
        );
        self::assertSame(['A_2.jpg'], $result['created']);
    }

    public function testImagesSharingTheSameAltTextArePairedByPosition(): void
    {
        $client = $this->mockClient([
            self::media([], []),
            self::created([ // newest first
                ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'Red tee'],
                ['id' => 'gid://shopify/MediaImage/1', 'alt' => 'Red tee'],
            ]),
            new Response(200, [], (string) json_encode(['data' => ['productReorderMedia' => ['job' => null, 'mediaUserErrors' => []]]])),
            self::saved(),
        ]);

        $client->products()->syncMedia('55', [
            MediaEntry::url(1, 'https://cdn.test/A_1.jpg', alt: 'Red tee'),
            MediaEntry::url(2, 'https://cdn.test/A_2.jpg', alt: 'Red tee'),
        ]);

        self::assertSame(
            ['A_1.jpg' => 'gid://shopify/MediaImage/1', 'A_2.jpg' => 'gid://shopify/MediaImage/2'],
            $this->savedMap(3),
        );
    }

    public function testNewMediaIsStillRecordedWhenShopifyNormalisesItsAltText(): void
    {
        $client = $this->mockClient([
            self::media([], []),
            self::created([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'Red tee']]), // trimmed by Shopify
            self::saved(),
        ]);

        $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg', alt: '  Red tee  ')]);

        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $this->savedMap(2));
    }

    public function testPruneRemovesTrackedMediaAndForgetsItsKey(): void
    {
        $client = $this->mockClient([
            self::media(
                [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'a'], ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'b'], ['id' => 'gid://shopify/MediaImage/3', 'alt' => 'merchant upload']],
                ['A_1.jpg' => 'gid://shopify/MediaImage/1', 'OLD_1.jpg' => 'gid://shopify/MediaImage/2'],
            ),
            self::replaced(),
            new Response(200, [], (string) json_encode(['data' => ['fileUpdate' => ['files' => [['id' => 'gid://shopify/MediaImage/2']], 'userErrors' => []]]])),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')], prune: true);

        // Only tracked media is pruned; the merchant's own upload (Media/3) is untouched.
        self::assertSame(['gid://shopify/MediaImage/2'], $result['pruned']);
        self::assertSame(['gid://shopify/MediaImage/2'], array_column($this->body(2)['variables']['files'], 'id'));
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $this->savedMap(3));
    }

    public function testAFailedMapSaveIsReportedWithoutLosingTheResult(): void
    {
        $client = $this->mockClient([
            self::media([]),
            self::created([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'A_1.jpg']]),
            new Response(200, [], (string) json_encode(['data' => ['metafieldsSet' => ['metafields' => [], 'userErrors' => [['field' => ['value'], 'message' => 'Access denied']]]]])),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertFalse($result['keyStoreSaved']);
        self::assertSame('gid://shopify/MediaImage/1', $result['media'][0]['id']); // still there to persist
        self::assertSame('MEDIA_KEY_STORE_FAILED', $result['userErrors'][0]['code']);
        self::assertStringContainsString('Access denied', $result['userErrors'][0]['message']);
    }

    public function testEnsureMediaSkipsByMapAndRecordsWhatItAdds(): void
    {
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'Front of the tee']], ['front' => 'gid://shopify/MediaImage/1']),
            self::created([['id' => 'gid://shopify/MediaImage/2', 'alt' => 'Back of the tee']]),
            self::saved(),
        ]);

        $result = $client->products()->ensureMedia('55', [
            Media::image('https://cdn.test/front.jpg', alt: 'Front of the tee', key: 'front'),
            Media::image('https://cdn.test/back.jpg', alt: 'Back of the tee', key: 'back'),
        ]);

        self::assertSame(['front'], $result['skipped']);
        self::assertCount(1, $result['added']);
        self::assertTrue($result['keyStoreSaved']);

        $sent = $this->body(1)['variables']['media'];
        self::assertCount(1, $sent);
        self::assertArrayNotHasKey('key', $sent[0]); // the key is ours, not part of CreateMediaInput
        self::assertSame(['back' => 'gid://shopify/MediaImage/2', 'front' => 'gid://shopify/MediaImage/1'], $this->savedMap(2));
    }

    public function testChainUsesTheJsonFileWhenTheMetafieldIsMissingAndRepairsIt(): void
    {
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'edited in admin']]), // metafield gone
            self::replaced(),
            self::saved(),
        ]);
        $file = new JsonFileMediaKeyStore($this->dir);
        $file->save(self::PRODUCT, ['A_1.jpg' => 'gid://shopify/MediaImage/1']);
        $client->useMediaKeyStore(new ChainMediaKeyStore(new MetafieldMediaKeyStore($client->graphql()), $file));

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        // The file knew the image, so it's replaced (not duplicated, not adopted by alt)…
        self::assertSame(['A_1.jpg'], $result['replaced']);
        self::assertSame([], $result['adopted']);
        // …and the missing metafield is written again. The file was already right: not rewritten.
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $this->savedMap(2));
        self::assertCount(3, $this->history);
    }

    public function testChainWritesBothStoresAndCreatesTheDirectory(): void
    {
        $client = $this->mockClient([
            self::media([]),
            self::created([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'A_1.jpg']]),
            self::saved(),
        ]);
        self::assertDirectoryDoesNotExist($this->dir);
        $file = new JsonFileMediaKeyStore($this->dir . '/shopify/media-keys');
        $client->useMediaKeyStore(new ChainMediaKeyStore(new MetafieldMediaKeyStore($client->graphql()), $file));

        $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $this->savedMap(2));
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $file->load(self::PRODUCT));
        self::assertFileExists($this->dir . '/shopify/media-keys/55/55.json');
    }

    public function testNothingIsChangedWhenNoKeyStoreCanBeRead(): void
    {
        $broken = new class implements MediaKeyStore {
            public function load(string $productGid): array
            {
                throw new MediaKeyStoreException('disk unreadable');
            }

            public function save(string $productGid, array $map): void
            {
            }

            public function forget(string $productGid): void
            {
            }
        };
        $client = $this->mockClient([self::media([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'x']])]);
        $client->useMediaKeyStore($broken);

        try {
            $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);
            self::fail('expected MediaKeyStoreException');
        } catch (MediaKeyStoreException $e) {
            // Without knowing what's already there, creating would risk duplicates.
            self::assertCount(1, $this->history); // only the read; no upload, create or replace
        }
    }
}
