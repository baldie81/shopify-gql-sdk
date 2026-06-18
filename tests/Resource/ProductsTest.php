<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Enum\ProductStatus;
use Baldie81\ShopifyGraphQL\Support\ProductInput;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class ProductsTest extends MockClientTestCase
{
    public function testGetSendsGidAndReturnsProduct(): void
    {
        $client  = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['product' => ['id' => 'gid://shopify/Product/123', 'title' => 'Hat']],
        ]))]);
        $product = $client->products()->get('123');

        self::assertSame('Hat', $product['title']);

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/admin/api/2025-07/graphql.json', (string) $request->getUri());

        $body = $this->body();
        self::assertStringContainsString('product(id: $id)', $body['query']);
        self::assertSame('gid://shopify/Product/123', $body['variables']['id']);
    }

    public function testGetReturnsNullWhenMissing(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode(['data' => ['product' => null]]))]);

        self::assertNull($client->products()->get('999'));
    }

    public function testSearchReturnsConnectionPage(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['products' => [
                'nodes'    => [['id' => 'gid://shopify/Product/1']],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'CUR'],
            ]],
        ]))]);

        $page = $client->products()->search('status:active', first: 10);

        self::assertCount(1, $page['nodes']);
        self::assertFalse($page['pageInfo']['hasNextPage']);

        $body = $this->body();
        self::assertSame(10, $body['variables']['first']);
        self::assertSame('status:active', $body['variables']['query']);
    }

    public function testSearchAllFollowsCursorAndMergesNodes(): void
    {
        $client = $this->mockClient([
            new Response(200, [], (string) json_encode(['data' => ['products' => [
                'nodes'    => [['id' => 'gid://shopify/Product/1'], ['id' => 'gid://shopify/Product/2']],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'CURSOR1'],
            ]]])),
            new Response(200, [], (string) json_encode(['data' => ['products' => [
                'nodes'    => [['id' => 'gid://shopify/Product/3']],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'CURSOR2'],
            ]]])),
        ]);

        $nodes = $client->products()->searchAll('vendor:Acme');

        self::assertCount(3, $nodes);
        self::assertCount(2, $this->history);

        $page1 = $this->body(0);
        self::assertSame(250, $page1['variables']['first']);   // default page size capped at 250
        self::assertNull($page1['variables']['after']);

        $page2 = $this->body(1);
        self::assertSame('CURSOR1', $page2['variables']['after']);
    }

    public function testCreateSendsProductCreateInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productCreate' => ['product' => ['id' => 'gid://shopify/Product/55'], 'userErrors' => []]],
        ]))]);

        $result = $client->products()->create(
            ProductInput::make('New Hat')->status(ProductStatus::Draft),
        );

        self::assertSame('gid://shopify/Product/55', $result['product']['id']);
        self::assertSame([], $result['userErrors']);

        $body = $this->body();
        self::assertStringContainsString('ProductCreateInput!', $body['query']);
        self::assertStringContainsString('productCreate(product: $product)', $body['query']);
        self::assertSame('New Hat', $body['variables']['product']['title']);
        self::assertSame('DRAFT', $body['variables']['product']['status']);
    }

    public function testUpdateInjectsGidIntoInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productUpdate' => ['product' => ['id' => 'gid://shopify/Product/55'], 'userErrors' => []]],
        ]))]);

        $client->products()->update('55', ProductInput::make()->vendor('Acme'));

        $body = $this->body();
        self::assertStringContainsString('ProductUpdateInput!', $body['query']);
        self::assertSame('gid://shopify/Product/55', $body['variables']['product']['id']);
        self::assertSame('Acme', $body['variables']['product']['vendor']);
    }

    public function testCreateSurfacesUserErrorsAsData(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productCreate' => [
                'product'    => null,
                'userErrors' => [['field' => ['title'], 'message' => 'Title can’t be blank']],
            ]],
        ]))]);

        $result = $client->products()->create(ProductInput::make());

        self::assertNull($result['product']);
        self::assertSame('Title can’t be blank', $result['userErrors'][0]['message']);
    }
}
