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

    public function testAllListsDefinitionsForOwnerType(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldDefinitions' => ['nodes' => [
                ['id' => 'gid://shopify/MetafieldDefinition/1', 'namespace' => 'custom', 'key' => 'erp_id', 'type' => ['name' => 'single_line_text_field']],
            ]]],
        ]))]);

        $nodes = $client->metafieldDefinitions()->all('PRODUCT');

        self::assertCount(1, $nodes);
        self::assertSame('erp_id', $nodes[0]['key']);
        $body = $this->body();
        self::assertSame('PRODUCT', $body['variables']['ownerType']);
        self::assertStringContainsString('metafieldDefinitions(ownerType:', $body['query']);
    }

    public function testUpdateSendsUpdateInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldDefinitionUpdate' => [
                'updatedDefinition' => ['id' => 'gid://shopify/MetafieldDefinition/1', 'name' => 'ERP id'],
                'userErrors'        => [],
            ]],
        ]))]);

        $result = $client->metafieldDefinitions()->update([
            'ownerType' => 'PRODUCT', 'namespace' => 'custom', 'key' => 'erp_id', 'name' => 'ERP id',
        ]);

        self::assertSame('ERP id', $result['definition']['name']);
        self::assertStringContainsString('MetafieldDefinitionUpdateInput!', $this->body()['query']);
    }

    public function testDeleteReturnsDeletedId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldDefinitionDelete' => [
                'deletedDefinitionId' => 'gid://shopify/MetafieldDefinition/1',
                'userErrors'          => [],
            ]],
        ]))]);

        $result = $client->metafieldDefinitions()->delete('gid://shopify/MetafieldDefinition/1', true);

        self::assertSame('gid://shopify/MetafieldDefinition/1', $result['deletedDefinitionId']);
        self::assertTrue($this->body()['variables']['deleteAll']);
    }

    public function testDefinePassesPinFlagToInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldDefinitionCreate' => ['createdDefinition' => ['id' => 'gid://1'], 'userErrors' => []]],
        ]))]);

        $client->metafieldDefinitions()->defineProduct('specs', 'material', MetafieldType::SingleLineText, 'Material', ['pin' => true]);

        self::assertTrue($this->body()['variables']['definition']['pin']);
    }

    public function testUpdatePassesPinFlagToInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldDefinitionUpdate' => ['updatedDefinition' => ['id' => 'gid://1'], 'userErrors' => []]],
        ]))]);

        $client->metafieldDefinitions()->update([
            'ownerType' => 'PRODUCT', 'namespace' => 'custom', 'key' => 'erp_id', 'name' => 'ERP id', 'pin' => false,
        ]);

        self::assertFalse($this->body()['variables']['definition']['pin']);
    }

    public function testAllSelectsPinnedPosition(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldDefinitions' => ['nodes' => []]],
        ]))]);

        $client->metafieldDefinitions()->all('PRODUCT');

        self::assertStringContainsString('pinnedPosition', $this->body()['query']);
    }
}
