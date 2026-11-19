<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Exception\UploadException;
use Baldie81\ShopifyGraphQL\GraphQL\Client;
use Baldie81\ShopifyGraphQL\Resource\Files;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class FilesTest extends TestCase
{
    /** @var list<array{request:RequestInterface,response:mixed,error:mixed,options:array}> */
    private array $gqlHistory = [];
    /** @var list<array{request:RequestInterface,response:mixed,error:mixed,options:array}> */
    private array $uploadHistory = [];

    /**
     * @param list<mixed> $gqlResponses
     * @param list<mixed> $uploadResponses
     */
    private function files(array $gqlResponses, array $uploadResponses = []): Files
    {
        $this->gqlHistory    = [];
        $this->uploadHistory = [];

        $gqlStack = HandlerStack::create(new MockHandler($gqlResponses));
        $gqlStack->push(Middleware::history($this->gqlHistory));
        $gql = new Client(new GuzzleClient([
            'handler'     => $gqlStack,
            'base_uri'    => 'https://t.myshopify.com/admin/api/2025-07/',
            'http_errors' => true,
        ]));

        $upStack = HandlerStack::create(new MockHandler($uploadResponses));
        $upStack->push(Middleware::history($this->uploadHistory));
        $uploader = new GuzzleClient(['handler' => $upStack, 'http_errors' => true]);

        return new Files($gql, $uploader);
    }

    private function gqlBody(int $i = 0): array
    {
        return json_decode((string) $this->gqlHistory[$i]['request']->getBody(), true);
    }

    public function testUploadCreatesStagedTargetThenPostsBytes(): void
    {
        $files = $this->files(
            [new Response(200, [], (string) json_encode(['data' => ['stagedUploadsCreate' => [
                'stagedTargets' => [[
                    'url'         => 'https://gcs.example/upload',
                    'resourceUrl' => 'https://gcs.example/resource/abc',
                    'parameters'  => [['name' => 'key', 'value' => 'k'], ['name' => 'policy', 'value' => 'p']],
                ]],
                'userErrors' => [],
            ]]]))],
            [new Response(201)],
        );

        $tmp = tempnam(sys_get_temp_dir(), 'sdk') . '.jpg';
        file_put_contents($tmp, 'imagebytes');

        try {
            $url = $files->upload($tmp, 'front.jpg', 'image/jpeg');
        } finally {
            @unlink($tmp);
        }

        self::assertSame('https://gcs.example/resource/abc', $url);

        $input = $this->gqlBody()['variables']['input'][0];
        self::assertSame('front.jpg', $input['filename']);
        self::assertSame('IMAGE', $input['resource']);
        self::assertSame('POST', $input['httpMethod']);

        $upload = $this->uploadHistory[0]['request'];
        self::assertSame('POST', $upload->getMethod());
        self::assertSame('https://gcs.example/upload', (string) $upload->getUri());
    }

    public function testUploadThrowsOnStagedUserErrors(): void
    {
        $files = $this->files([new Response(200, [], (string) json_encode(['data' => ['stagedUploadsCreate' => [
            'stagedTargets' => [],
            'userErrors'    => [['field' => ['filename'], 'message' => 'bad']],
        ]]]))]);

        $tmp = tempnam(sys_get_temp_dir(), 'sdk') . '.jpg';
        file_put_contents($tmp, 'x');

        $this->expectException(UploadException::class);
        try {
            $files->upload($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testUploadThrowsWhenFileMissing(): void
    {
        $files = $this->files([]);

        $this->expectException(UploadException::class);
        $files->upload('/no/such/file.jpg');
    }

    public function testUpdateSendsFileUpdate(): void
    {
        $files = $this->files([new Response(200, [], (string) json_encode(['data' => ['fileUpdate' => [
            'files'      => [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'k']],
            'userErrors' => [],
        ]]]))]);

        $result = $files->update([['id' => 'gid://shopify/MediaImage/1', 'originalSource' => 'https://x/y', 'alt' => 'k']]);

        self::assertSame('gid://shopify/MediaImage/1', $result['files'][0]['id']);
        $body = $this->gqlBody();
        self::assertStringContainsString('fileUpdate', $body['query']);
        self::assertSame('https://x/y', $body['variables']['files'][0]['originalSource']);
    }

    public function testUploadManyBatchesOneStagedCreateThenPushesEach(): void
    {
        $files = $this->files(
            [new Response(200, [], (string) json_encode(['data' => ['stagedUploadsCreate' => [
                'stagedTargets' => [
                    ['url' => 'https://gcs/u1', 'resourceUrl' => 'https://gcs/r1', 'parameters' => [['name' => 'key', 'value' => 'k1']]],
                    ['url' => 'https://gcs/u2', 'resourceUrl' => 'https://gcs/r2', 'parameters' => [['name' => 'key', 'value' => 'k2']]],
                ],
                'userErrors' => [],
            ]]]))],
            [new Response(201), new Response(201)],
        );

        $a = tempnam(sys_get_temp_dir(), 'sdk') . '.jpg';
        $b = tempnam(sys_get_temp_dir(), 'sdk') . '.png';
        file_put_contents($a, 'a');
        file_put_contents($b, 'b');

        try {
            $urls = $files->uploadMany([$a, $b]);
        } finally {
            @unlink($a);
            @unlink($b);
        }

        self::assertSame(['https://gcs/r1', 'https://gcs/r2'], $urls);
        self::assertCount(2, $this->gqlBody()['variables']['input']); // ONE stagedUploadsCreate, two inputs
        self::assertCount(1, $this->gqlHistory);
        self::assertCount(2, $this->uploadHistory); // two byte pushes
    }

    public function testStageJsonlUsesBulkResourceAndReturnsKeyParameter(): void
    {
        $files = $this->files(
            [new Response(200, [], (string) json_encode(['data' => ['stagedUploadsCreate' => [
                'stagedTargets' => [[
                    'url'         => 'https://gcs/bulk',
                    'resourceUrl' => 'https://gcs/bulk/res',
                    'parameters'  => [['name' => 'key', 'value' => 'tmp/bulk/vars.jsonl']],
                ]],
                'userErrors' => [],
            ]]]))],
            [new Response(201)],
        );

        $path = $files->stageJsonl("{\"a\":1}\n{\"b\":2}");

        self::assertSame('tmp/bulk/vars.jsonl', $path);
        self::assertSame('BULK_MUTATION_VARIABLES', $this->gqlBody()['variables']['input'][0]['resource']);
        self::assertCount(1, $this->uploadHistory);
    }

    public function testUploadThrowsWhenTheStorageHostRejectsTheBytesWithoutAnException(): void
    {
        // An injected uploader with http_errors off doesn't throw on a 403.
        $gql = new Client(new GuzzleClient([
            'handler'  => HandlerStack::create(new MockHandler([new Response(200, [], (string) json_encode(['data' => ['stagedUploadsCreate' => [
                'stagedTargets' => [['url' => 'https://gcs.example/upload', 'resourceUrl' => 'https://gcs.example/resource/abc', 'parameters' => []]],
                'userErrors'    => [],
            ]]]))])),
            'base_uri' => 'https://t.myshopify.com/admin/api/2026-10/',
        ]));
        $uploader = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([new Response(403, [], 'expired')])), 'http_errors' => false]);

        $path = tempnam(sys_get_temp_dir(), 'sdk');
        file_put_contents($path, 'x');

        try {
            (new Files($gql, $uploader))->upload($path, 'a.png');
            self::fail('expected UploadException');
        } catch (UploadException $e) {
            self::assertStringContainsString('HTTP 403', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }
}
