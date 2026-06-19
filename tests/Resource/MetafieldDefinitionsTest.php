<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Enum\MetafieldType;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class MetafieldDefinitionsTest extends MockClientTestCase
{
    public function testDefineProductSendsDefinitionInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldDefinitionCreate' => [
                'createdDefinition' => ['id' => 'gid://shopify/MetafieldDefinition/1', 'namespace' => 'specs', 'key' => 'material'],
                'userErrors'        => [],
            ]],
        ]))]);

        $result = $client->metafieldDefinitions()->defineProduct('specs', 'material', MetafieldType::SingleLineText, 'Material');

        self::assertSame('gid://shopify/MetafieldDefinition/1', $result['definition']['id']);
        self::assertSame([], $result['userErrors']);

        $body = $this->body();
        self::assertStringContainsString('MetafieldDefinitionInput!', $body['query']);
        $def = $body['variables']['definition'];
        self::assertSame('PRODUCT', $def['ownerType']);
        self::assertSame('specs', $def['namespace']);
        self::assertSame('material', $def['key']);
        self::assertSame('single_line_text_field', $def['type']);
        self::assertSame('Material', $def['name']);
    }

    public function testCreateSurfacesUserErrors(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldDefinitionCreate' => [
                'createdDefinition' => null,
                'userErrors'        => [['field' => ['key'], 'message' => 'is already taken', 'code' => 'TAKEN']],
            ]],
        ]))]);

        $result = $client->metafieldDefinitions()->defineProduct('specs', 'material', 'single_line_text_field', 'Material');

        self::assertNull($result['definition']);
        self::assertSame('TAKEN', $result['userErrors'][0]['code']);
    }
}
