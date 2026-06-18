<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Enum\ProductTranslatableField;
use Baldie81\ShopifyGraphQL\Support\ProductField;
use Baldie81\ShopifyGraphQL\Support\ProductInput;
use Baldie81\ShopifyGraphQL\Support\Translation;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class TranslationsTest extends MockClientTestCase
{
    private static function content(array $entries): string
    {
        return (string) json_encode(['data' => ['translatableResource' => ['translatableContent' => $entries]]]);
    }

    private static function registered(): string
    {
        return (string) json_encode(['data' => ['translationsRegister' => ['translations' => [], 'userErrors' => []]]]);
    }

    public function testSetLooksUpDigestsThenRegisters(): void
    {
        $client = $this->mockClient([
            new Response(200, [], self::content([
                ['key' => 'title', 'digest' => 'D_TITLE', 'locale' => 'en'],
                ['key' => 'body_html', 'digest' => 'D_BODY', 'locale' => 'en'],
            ])),
            new Response(200, [], self::registered()),
        ]);

        $result = $client->translations()->set('gid://shopify/Product/55', 'fr', [
            'title'     => 'Chapeau',
            'body_html' => '<p>desc</p>',
        ]);

        self::assertSame([], $result['skipped']);
        self::assertCount(2, $this->history);

        $vars = $this->body(1)['variables'];
        self::assertSame('gid://shopify/Product/55', $vars['resourceId']);
        self::assertSame(
            ['locale' => 'fr', 'key' => 'title', 'value' => 'Chapeau', 'translatableContentDigest' => 'D_TITLE'],
            $vars['translations'][0],
        );
        self::assertSame('D_BODY', $vars['translations'][1]['translatableContentDigest']);
    }

    public function testSetSkipsKeysThatAreNotTranslatable(): void
    {
        $client = $this->mockClient([
            new Response(200, [], self::content([['key' => 'title', 'digest' => 'D', 'locale' => 'en']])),
            new Response(200, [], self::registered()),
        ]);

        $result = $client->translations()->set('gid://shopify/Product/55', 'fr', [
            'title'   => 'X',
            'made_up' => 'Y',
        ]);

        self::assertSame(['made_up'], $result['skipped']);
        self::assertCount(1, $this->body(1)['variables']['translations']);
    }

    public function testSetWithNoMatchableKeysMakesNoMutation(): void
    {
        $client = $this->mockClient([
            new Response(200, [], self::content([['key' => 'title', 'digest' => 'D', 'locale' => 'en']])),
        ]);

        $result = $client->translations()->set('gid://shopify/Product/55', 'fr', ['nope' => 'Y']);

        self::assertSame(['nope'], $result['skipped']);
        self::assertSame([], $result['translations']);
        self::assertCount(1, $this->history); // only the digest lookup
    }

    public function testRegisterAutoFillsMissingDigest(): void
    {
        $client = $this->mockClient([
            new Response(200, [], self::content([['key' => 'title', 'digest' => 'D', 'locale' => 'en']])),
            new Response(200, [], self::registered()),
        ]);

        $client->translations()->register('gid://shopify/Product/55', [
            Translation::make('fr', ProductTranslatableField::Title, 'Chapeau'),
        ]);

        self::assertCount(2, $this->history);
        self::assertSame('D', $this->body(1)['variables']['translations'][0]['translatableContentDigest']);
    }

    public function testRegisterWithProvidedDigestSkipsLookup(): void
    {
        $client = $this->mockClient([new Response(200, [], self::registered())]);

        $client->translations()->register('gid://shopify/Product/55', [
            Translation::make('fr', 'title', 'Chapeau', 'PROVIDED'),
        ]);

        self::assertCount(1, $this->history); // no translatableResource lookup
        self::assertSame('PROVIDED', $this->body()['variables']['translations'][0]['translatableContentDigest']);
    }

    public function testProductSetTranslationsUsesFieldsAcrossLocales(): void
    {
        $client = $this->mockClient([
            new Response(200, [], self::content([
                ['key' => 'title', 'digest' => 'D_TITLE', 'locale' => 'en'],
                ['key' => 'body_html', 'digest' => 'D_BODY', 'locale' => 'en'],
            ])),
            new Response(200, [], self::registered()),
        ]);

        $result = $client->products()->setTranslations('55', [
            ProductField::title()->withTranslation('fr', 'Robe'),
            ProductField::description()
                ->withTranslation('fr', '<p>Une robe</p>')
                ->withTranslation('es', '<p>Un vestido</p>'),
        ]);

        self::assertSame([], $result['skipped']);
        self::assertSame('gid://shopify/Product/55', $this->body(0)['variables']['resourceId']);

        $sent = $this->body(1)['variables']['translations'];
        self::assertCount(3, $sent); // title/fr + body_html/fr + body_html/es
        self::assertSame(
            ['locale' => 'fr', 'key' => 'title', 'value' => 'Robe', 'translatableContentDigest' => 'D_TITLE'],
            $sent[0],
        );
        self::assertSame('body_html', $sent[1]['key']);
        self::assertSame('D_BODY', $sent[1]['translatableContentDigest']);
        self::assertSame('es', $sent[2]['locale']);
    }

    public function testProductSetTranslationsSkipsNonTranslatableFields(): void
    {
        $client = $this->mockClient([
            new Response(200, [], self::content([['key' => 'title', 'digest' => 'D', 'locale' => 'en']])),
            new Response(200, [], self::registered()),
        ]);

        $result = $client->products()->setTranslations('55', [
            ProductField::title()->withTranslation('fr', 'Robe'),
            ProductField::seoTitle()->withTranslation('fr', 'SEO'), // meta_title not translatable here
        ]);

        self::assertSame(['meta_title'], $result['skipped']);
        self::assertCount(1, $this->body(1)['variables']['translations']);
    }

    public function testCreateRegistersAttachedTranslations(): void
    {
        $client = $this->mockClient([
            new Response(200, [], (string) json_encode(['data' => ['productCreate' => [
                'product' => ['id' => 'gid://shopify/Product/77'], 'userErrors' => [],
            ]]])),
            new Response(200, [], self::content([
                ['key' => 'title', 'digest' => 'D_T', 'locale' => 'en'],
                ['key' => 'body_html', 'digest' => 'D_B', 'locale' => 'en'],
            ])),
            new Response(200, [], self::registered()),
        ]);

        $result = $client->products()->create(
            ProductInput::make()
                ->field(ProductField::title('Hat')->withTranslation('fr', 'Chapeau'))
                ->field(ProductField::description('<p>en</p>')->withTranslation('fr', '<p>fr</p>')),
        );

        self::assertSame('gid://shopify/Product/77', $result['product']['id']);
        self::assertArrayHasKey('translations', $result);
        self::assertSame([], $result['translations']['skipped']);
        self::assertCount(3, $this->history); // productCreate + digests + translationsRegister

        // base values rode in the create input
        $createVars = $this->body(0)['variables']['product'];
        self::assertSame('Hat', $createVars['title']);
        self::assertSame('<p>en</p>', $createVars['descriptionHtml']);

        // translations registered against the new product id, with the right digests
        self::assertSame('gid://shopify/Product/77', $this->body(2)['variables']['resourceId']);
        self::assertSame('D_T', $this->body(2)['variables']['translations'][0]['translatableContentDigest']);
    }

    public function testCreateWithoutAttachedTranslationsIsSingleCall(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productCreate' => ['product' => ['id' => 'gid://shopify/Product/1'], 'userErrors' => []]],
        ]))]);

        $result = $client->products()->create(ProductInput::make('Plain'));

        self::assertArrayNotHasKey('translations', $result);
        self::assertCount(1, $this->history);
    }

    public function testRemoveSendsKeysAndLocales(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['translationsRemove' => ['translations' => [], 'userErrors' => []]],
        ]))]);

        $client->translations()->remove('gid://shopify/Product/55', [ProductTranslatableField::Title, 'body_html'], ['fr']);

        $vars = $this->body()['variables'];
        self::assertSame(['title', 'body_html'], $vars['keys']);
        self::assertSame(['fr'], $vars['locales']);
    }
}
