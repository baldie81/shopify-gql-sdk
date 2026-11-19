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
use Baldie81\ShopifyGraphQL\Exception\TransportException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
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
     * A stored map names the product it was written for; pass `$owner: null` for
     * a map written before that was recorded (1.4.0/1.4.1), or another product's
     * GID for a map copied along with a duplicated product.
     *
     * @param list<array<string,mixed>>  $nodes
     * @param array<string,string>|null  $map
     */
    private static function media(array $nodes, ?array $map = null, ?string $owner = self::PRODUCT): Response
    {
        if ($map !== null && $owner !== null) {
            $map[MediaKeyStore::OWNER_KEY] = $owner;
        }

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
     * The keys written by the metafieldsSet request at $index. The map must be
     * stamped with the product it belongs to; that entry is checked and left out.
     *
     * @return array<string,string>
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

        $map = json_decode($input['value'], true);
        self::assertSame(self::PRODUCT, $map[MediaKeyStore::OWNER_KEY] ?? null);
        unset($map[MediaKeyStore::OWNER_KEY]);

        return $map;
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

    public function testAMapCopiedFromADuplicatedProductIsIgnoredAndMediaAdopted(): void
    {
        // The map was copied with the product: it names the original, and so do its ids.
        $client = $this->mockClient([
            self::media(
                // Even an id that happens to match is not trusted from another product's map.
                [['id' => 'gid://shopify/MediaImage/900', 'alt' => 'A_1.jpg'], ['id' => 'gid://shopify/MediaImage/2', 'alt' => '']],
                ['A_1.jpg' => 'gid://shopify/MediaImage/1', 'B_1.jpg' => 'gid://shopify/MediaImage/2'],
                owner: 'gid://shopify/Product/1',
            ),
            self::replaced(),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame(['A_1.jpg'], $result['adopted']);
        self::assertSame('gid://shopify/MediaImage/900', $this->body(1)['variables']['files'][0]['id']); // never the stale id
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/900'], $this->savedMap(2)); // re-stamped for this product
    }

    public function testAStaleMapWithNoOwnerRecordedStillFallsBackToAdoption(): void
    {
        // Written by 1.4.0/1.4.1, which didn't record the owner: can't be told
        // apart from a duplicated product's map, so it is adopted as before.
        $client = $this->mockClient([
            self::media(
                [['id' => 'gid://shopify/MediaImage/900', 'alt' => 'A_1.jpg']],
                ['A_1.jpg' => 'gid://shopify/MediaImage/1'],
                owner: null,
            ),
            self::replaced(),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame(['A_1.jpg'], $result['adopted']);
        self::assertSame('gid://shopify/MediaImage/900', $this->body(1)['variables']['files'][0]['id']); // never the stale id
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/900'], $this->savedMap(2));
    }

    public function testAMapWithNoOwnerRecordedIsStampedOnTheNextSync(): void
    {
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'x']], ['A_1.jpg' => 'gid://shopify/MediaImage/1'], owner: null),
            self::replaced(),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame(['A_1.jpg'], $result['replaced']);
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $this->savedMap(2)); // savedMap() checks the owner
    }

    public function testAProductsOwnStaleMapDoesNotAdoptOrPruneTheMerchantsImages(): void
    {
        // The one tracked image was deleted in the admin, and the merchant added
        // their own. The map is this product's, so nothing is adopted by alt text.
        $client = $this->mockClient([
            self::media(
                [['id' => 'gid://shopify/MediaImage/5', 'alt' => 'Merchant photo']],
                ['OLD_1.jpg' => 'gid://shopify/MediaImage/GONE'],
            ),
            self::created([['id' => 'gid://shopify/MediaImage/6', 'alt' => 'NEW_1.jpg']]),
            new Response(200, [], (string) json_encode(['data' => ['productReorderMedia' => ['job' => null, 'mediaUserErrors' => []]]])),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/NEW_1.jpg')], prune: true);

        self::assertSame([], $result['adopted']);
        self::assertSame([], $result['pruned']);
        self::assertSame(['NEW_1.jpg' => 'gid://shopify/MediaImage/6'], $this->savedMap(3));
        self::assertCount(4, $this->history); // read, create, move to first place, save — no detach
    }

    public function testAnEmptyMapOfItsOwnAlsoStopsAdoption(): void
    {
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/5', 'alt' => 'A_1.jpg']], []),
            self::created([['id' => 'gid://shopify/MediaImage/6', 'alt' => 'A_1.jpg']]),
            new Response(200, [], (string) json_encode(['data' => ['productReorderMedia' => ['job' => null, 'mediaUserErrors' => []]]])),
            self::saved(),
        ]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/A_1.jpg')]);

        self::assertSame([], $result['adopted']);
        self::assertSame(['A_1.jpg'], $result['created']);
    }

    public function testARejectedReplaceIsNotReportedAsReplaced(): void
    {
        $client = $this->mockClient([
            self::media(
                [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'a'], ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'b']],
                ['A_1.jpg' => 'gid://shopify/MediaImage/1', 'A_2.jpg' => 'gid://shopify/MediaImage/2'],
            ),
            new Response(200, [], (string) json_encode(['data' => ['fileUpdate' => [
                'files'      => [['id' => 'gid://shopify/MediaImage/2', 'alt' => 'b']],
                'userErrors' => [['field' => ['files', '0'], 'message' => 'Invalid image', 'code' => 'INVALID']],
            ]]])),
            new Response(200, [], (string) json_encode(['data' => ['productReorderMedia' => ['job' => null, 'mediaUserErrors' => []]]])),
        ]);

        $result = $client->products()->syncMedia('55', [
            MediaEntry::url(1, 'https://cdn.test/A_1.jpg'),
            MediaEntry::url(2, 'https://cdn.test/A_2.jpg'),
        ]);

        self::assertSame(['A_2.jpg'], $result['replaced']);
        self::assertSame(['unchanged', 'replaced'], array_column($result['media'], 'action'));
        self::assertSame('Invalid image', $result['userErrors'][0]['message']);
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
            $this->savedMap(3),
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
            self::saved(),
        ]);

        $client->products()->syncMedia('55', [
            MediaEntry::url(1, 'https://cdn.test/A_1.jpg', alt: 'Red tee'),
            MediaEntry::url(2, 'https://cdn.test/A_2.jpg', alt: 'Red tee'),
        ]);

        self::assertSame(
            ['A_1.jpg' => 'gid://shopify/MediaImage/1', 'A_2.jpg' => 'gid://shopify/MediaImage/2'],
            $this->savedMap(2),
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

    public function testTheMapIsSavedWhenARequestFailsAfterMediaWasCreated(): void
    {
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'A_1.jpg']], ['A_1.jpg' => 'gid://shopify/MediaImage/1']),
            self::replaced(),
            self::created([['id' => 'gid://shopify/MediaImage/2', 'alt' => 'A_2.jpg']]),
            // the reorder times out; a mutation is never retried
            new ConnectException('timed out', new Request('POST', 'graphql.json')),
            self::saved(),
        ]);

        try {
            $client->products()->syncMedia('55', [
                MediaEntry::url(2, 'https://cdn.test/A_1.jpg'),
                MediaEntry::url(1, 'https://cdn.test/A_2.jpg'), // new and first: needs a move
            ]);
            self::fail('The reorder failure should have been rethrown.');
        } catch (TransportException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }

        // The new image is on record, so the next sync replaces it instead of adding it again.
        self::assertCount(5, $this->history);
        self::assertSame(
            ['A_1.jpg' => 'gid://shopify/MediaImage/1', 'A_2.jpg' => 'gid://shopify/MediaImage/2'],
            $this->savedMap(4),
        );
    }

    public function testAFailedMapSaveDoesNotMaskTheOriginalFailure(): void
    {
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'A_1.jpg']], ['A_1.jpg' => 'gid://shopify/MediaImage/1']),
            self::replaced(),
            self::created([['id' => 'gid://shopify/MediaImage/2', 'alt' => 'A_2.jpg']]),
            new ConnectException('reorder timed out', new Request('POST', 'graphql.json')),
            new ConnectException('save timed out', new Request('POST', 'graphql.json')),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('reorder timed out');

        $client->products()->syncMedia('55', [
            MediaEntry::url(2, 'https://cdn.test/A_1.jpg'),
            MediaEntry::url(1, 'https://cdn.test/A_2.jpg'),
        ]);
    }

    public function testEnsureMediaRecordsEarlierChunksWhenALaterOneFails(): void
    {
        $media   = [];
        $created = [];
        for ($i = 1; $i <= 251; $i++) {
            $media[] = Media::image("https://cdn.test/{$i}.jpg", key: "k{$i}");
            if ($i <= 250) {
                array_unshift($created, ['id' => "gid://shopify/MediaImage/{$i}", 'alt' => "k{$i}"]); // newest first
            }
        }

        $client = $this->mockClient([
            self::media([], []),
            self::created($created),
            new ConnectException('timed out', new Request('POST', 'graphql.json')),
            self::saved(),
        ]);

        try {
            $client->products()->ensureMedia('55', $media);
            self::fail('The failure of the second chunk should have been rethrown.');
        } catch (TransportException) {
        }

        $map = $this->savedMap(3);
        self::assertCount(250, $map);
        self::assertSame('gid://shopify/MediaImage/7', $map['k7']);
        self::assertArrayNotHasKey('k251', $map);
    }

    public function testAnUnchangedMapWithNumericKeysIsNotRewritten(): void
    {
        // Numeric keys ("9") become PHP integers. Sorted alongside other keys
        // with the default flags, the result depends on the order the entries
        // arrive in, which made an unchanged map stored in this order look changed.
        $stored = [
            '9'                      => 'gid://shopify/MediaImage/1',
            '1a'                     => 'gid://shopify/MediaImage/2',
            MediaKeyStore::OWNER_KEY => self::PRODUCT,
            '10'                     => 'gid://shopify/MediaImage/3',
            'a'                      => 'gid://shopify/MediaImage/4',
        ];
        $nodes = [];
        foreach ([1, 2, 3, 4] as $n) {
            $nodes[] = ['id' => "gid://shopify/MediaImage/{$n}", 'alt' => 'x'];
        }

        $client = $this->mockClient([self::media($nodes, $stored, owner: null)]);
        $result = $client->products()->ensureMedia('55', []);

        self::assertTrue($result['keyStoreSaved']);
        self::assertCount(1, $this->history); // the read only: nothing to write
    }

    /**
     * The moves sent by the reorder request at $index, as `id suffix => position`.
     *
     * @return array<string,string>
     */
    private function moves(int $index): array
    {
        $body = $this->body($index);
        self::assertStringContainsString('productReorderMedia', $body['query']);

        $moves = [];
        foreach ($body['variables']['moves'] as $move) {
            $moves[substr($move['id'], strlen('gid://shopify/MediaImage/'))] = $move['newPosition'];
        }

        return $moves;
    }

    private static function reordered(): Response
    {
        return new Response(200, [], (string) json_encode(['data' => ['productReorderMedia' => ['job' => null, 'mediaUserErrors' => []]]]));
    }

    public function testSyncingOnlyLaterSlotsLeavesTheMainImageInPlace(): void
    {
        $map = ['S_1.jpg' => 'gid://shopify/MediaImage/1', 'S_2.jpg' => 'gid://shopify/MediaImage/2', 'S_3.jpg' => 'gid://shopify/MediaImage/3'];
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/1'], ['id' => 'gid://shopify/MediaImage/2'], ['id' => 'gid://shopify/MediaImage/3']], $map),
            self::replaced(),
        ]);

        $client->products()->syncMedia('55', [
            MediaEntry::url(2, 'https://cdn.test/S_2.jpg'),
            MediaEntry::url(3, 'https://cdn.test/S_3.jpg'),
        ]);

        self::assertCount(2, $this->history); // read + replace: everything is where its slot says, nothing moves
    }

    public function testASingleNewSlotIsMovedToItsPosition(): void
    {
        $map = ['S_2.jpg' => 'gid://shopify/MediaImage/2', 'S_3.jpg' => 'gid://shopify/MediaImage/3'];
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/2'], ['id' => 'gid://shopify/MediaImage/3']], $map),
            self::created([['id' => 'gid://shopify/MediaImage/9', 'alt' => 'S_1.jpg']]),
            self::reordered(),
            self::saved(),
        ]);

        $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/S_1.jpg')]);

        self::assertSame(['9' => '0'], $this->moves(2)); // appended last by Shopify, made the main image
    }

    public function testSlotsArePlacedAroundMediaTheSyncDoesNotTouch(): void
    {
        // On the product: merchant image M first, then ours in the wrong order.
        $map = ['S_1.jpg' => 'gid://shopify/MediaImage/1', 'S_3.jpg' => 'gid://shopify/MediaImage/3'];
        $client = $this->mockClient([
            self::media([['id' => 'gid://shopify/MediaImage/M'], ['id' => 'gid://shopify/MediaImage/3'], ['id' => 'gid://shopify/MediaImage/1']], $map),
            self::replaced(),
            self::reordered(),
        ]);

        $client->products()->syncMedia('55', [
            MediaEntry::url(1, 'https://cdn.test/S_1.jpg'),
            MediaEntry::url(3, 'https://cdn.test/S_3.jpg'),
        ]);

        // Target: 1, M, 3. Moving 1 to the front leaves M second and 3 third already.
        self::assertSame(['1' => '0'], $this->moves(2));
    }

    public function testMediaAdoptedWithoutPruningIsNotPrunedLaterUnlessASyncNamedIt(): void
    {
        // Sync 1, no prune, on a product with no map: both alts are adopted.
        $nodes  = [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'S_1.jpg'], ['id' => 'gid://shopify/MediaImage/7', 'alt' => 'Lifestyle shot']];
        $client = $this->mockClient([self::media($nodes), self::replaced(), self::saved()]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/S_1.jpg')]);

        self::assertSame(['S_1.jpg', 'Lifestyle shot'], $result['adopted']);
        $saved = json_decode($this->body(2)['variables']['metafields'][0]['value'], true);
        self::assertSame('["Lifestyle shot"]', $saved[MediaKeyStore::ADOPTED_KEY]); // S_1.jpg was named: confirmed

        // Sync 2 with prune: the merchant's image was only ever a guess, so it stays.
        $client = $this->mockClient([self::media($nodes, $saved, owner: null), self::replaced()]);

        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/S_1.jpg')], prune: true);

        self::assertSame([], $result['pruned']);
        self::assertCount(2, $this->history); // read + replace: no detach, and the map is unchanged
    }

    public function testAnAdoptedKeyIsPrunableOnceASyncHasNamedIt(): void
    {
        $nodes = [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'S_1.jpg'], ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'S_2.jpg']];
        $map   = [
            'S_1.jpg'                  => 'gid://shopify/MediaImage/1',
            'S_2.jpg'                  => 'gid://shopify/MediaImage/2',
            MediaKeyStore::ADOPTED_KEY => '["S_2.jpg"]',
        ];

        // S_2.jpg is named here, which confirms it...
        $client = $this->mockClient([self::media($nodes, $map), self::replaced(), self::saved()]);
        $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/S_1.jpg'), MediaEntry::url(2, 'https://cdn.test/S_2.jpg')]);
        self::assertSame(['S_1.jpg' => 'gid://shopify/MediaImage/1', 'S_2.jpg' => 'gid://shopify/MediaImage/2'], $this->savedMap(2));

        // ...so a later mirror sync without it removes it.
        unset($map[MediaKeyStore::ADOPTED_KEY]);
        $client = $this->mockClient([
            self::media($nodes, $map),
            self::replaced(),
            new Response(200, [], (string) json_encode(['data' => ['fileUpdate' => ['files' => [['id' => 'gid://shopify/MediaImage/2']], 'userErrors' => []]]])),
            self::saved(),
        ]);
        $result = $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/S_1.jpg')], prune: true);

        self::assertSame(['gid://shopify/MediaImage/2'], $result['pruned']);
    }

    public function testReservedKeysAreRejectedAsSlotKeys(): void
    {
        $client = $this->mockClient([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');

        $client->products()->syncMedia('55', [MediaEntry::url(1, 'https://cdn.test/a.jpg', key: MediaKeyStore::ADOPTED_KEY)]);
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
        // …and the missing metafield is written again; the file gains the owner entry it lacked.
        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $this->savedMap(2));
        self::assertSame(self::PRODUCT, $file->load(self::PRODUCT)[MediaKeyStore::OWNER_KEY]);
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
        self::assertSame(
            [MediaKeyStore::OWNER_KEY => self::PRODUCT, 'A_1.jpg' => 'gid://shopify/MediaImage/1'],
            $file->load(self::PRODUCT),
        );
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
