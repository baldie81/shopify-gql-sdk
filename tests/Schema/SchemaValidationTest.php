<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Schema;

use GraphQL\Executor\Values;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildClientSchema;
use GraphQL\Utils\TypeInfo;
use GraphQL\Validator\DocumentValidator;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Configuration;
use Baldie81\ShopifyGraphQL\Enum\MetafieldType;
use Baldie81\ShopifyGraphQL\Enum\WebhookTopic;
use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Address;
use Baldie81\ShopifyGraphQL\Support\CollectionInput;
use Baldie81\ShopifyGraphQL\Support\CustomerInput;
use Baldie81\ShopifyGraphQL\Support\DraftOrderInput;
use Baldie81\ShopifyGraphQL\Support\GiftCardInput;
use Baldie81\ShopifyGraphQL\Support\Media;
use Baldie81\ShopifyGraphQL\Support\MediaEntry;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use Baldie81\ShopifyGraphQL\Support\PriceListInput;
use Baldie81\ShopifyGraphQL\Support\ProductField;
use Baldie81\ShopifyGraphQL\Support\ProductInput;
use Baldie81\ShopifyGraphQL\Support\Translation;
use Baldie81\ShopifyGraphQL\Support\VariantInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Validates every operation the SDK sends against Shopify's real Admin GraphQL
 * schema, for each supported API version: the document itself (unknown fields/arguments, wrong types), the
 * variables the builders produce (coerced against the declared input types),
 * and use of deprecated fields.
 *
 * The rest of the suite mocks Shopify's responses, so on its own it can't notice
 * a query Shopify would reject. This test drives each public method against a
 * recording transport and checks what was sent.
 *
 * The schemas aren't committed (each is several MB and Shopify's). Fetch them with
 * `composer schema`; a version whose schema is missing is skipped (or fails, when the
 * SHOPIFY_SCHEMA_REQUIRED environment variable is set, as it is in CI).
 */
final class SchemaValidationTest extends TestCase
{

    /**
     * Deprecated members the SDK still uses on purpose, as `Type.field`.
     */
    private const array ALLOWED_DEPRECATIONS = [
        // The replacement (`barcodes`) is not available on every API version the SDK supports.
        'ProductVariantsBulkInput.barcode',
        // Used when customLineItem() is called without a currency.
        'DraftOrderLineItemInput.originalUnitPrice',
        // Replaced by collectionUpdate inclusions, which older supported API versions lack.
        'Mutation.collectionAddProducts',
        'Mutation.collectionRemoveProducts',
    ];


    /** @var list<array{query:string,variables:array<string,mixed>}> */
    private array $sent = [];

    /**
     * @return iterable<string,array{string}>
     */
    public static function versions(): iterable
    {
        // Each version is validated separately: the SDK sends different operations depending on it.
        foreach (Configuration::SUPPORTED_API_VERSIONS as $version) {
            yield $version => [$version];
        }
    }

    #[DataProvider('versions')]
    public function testEveryOperationIsValidAgainstTheSchema(string $version): void
    {
        $schema = self::schema($version);
        if ($schema === null) {
            // CI sets this so a missing schema can't pass as a green build.
            if (getenv('SHOPIFY_SCHEMA_REQUIRED')) {
                self::fail("The {$version} schema was not found, and SHOPIFY_SCHEMA_REQUIRED is set. Run `composer schema`.");
            }
            self::markTestSkipped("The {$version} schema was not found. Run `composer schema` to download it.");
        }

        $this->sent = [];
        $this->exerciseEveryMethod($version);

        $problems = [];
        foreach ($this->sent as $operation) {
            foreach ($this->check($schema, $operation['query'], $operation['variables']) as $problem) {
                $problems[$problem . "\n    in: " . $operation['query']] = true;
            }
        }

        self::assertGreaterThan(90, count($this->sent), 'Expected the whole public surface to be exercised.');
        self::assertSame([], array_keys($problems), "Operations rejected by the {$version} schema");
    }

    private static function schema(string $version): ?Schema
    {
        $file = dirname(__DIR__) . "/fixtures/schema/{$version}.json";
        if (!is_file($file)) {
            return null;
        }

        return BuildClientSchema::build((array) json_decode((string) file_get_contents($file), true), ['assumeValid' => true]);
    }

    /**
     * @param array<string,mixed> $variables
     * @return list<string>
     */
    private function check(Schema $schema, string $query, array $variables): array
    {
        try {
            $document = Parser::parse($query);
        } catch (\Throwable $e) {
            return ['Syntax error: ' . $e->getMessage()];
        }

        $problems = [];
        foreach (DocumentValidator::validate($schema, $document) as $error) {
            $problems[] = $error->getMessage();
        }
        if ($problems !== []) {
            return $problems;
        }

        // Deprecated output fields selected by the document.
        $typeInfo = new TypeInfo($schema);
        Visitor::visit($document, Visitor::visitWithTypeInfo($typeInfo, [
            'enter' => static function ($node) use ($typeInfo, &$problems): void {
                if (!$node instanceof FieldNode) {
                    return;
                }
                $field  = $typeInfo->getFieldDef();
                $parent = $typeInfo->getParentType();
                if ($field !== null && $parent !== null && $field->deprecationReason !== null) {
                    $name = $parent->name . '.' . $field->name;
                    if (!in_array($name, self::ALLOWED_DEPRECATIONS, true)) {
                        $problems[] = "Deprecated field {$name}: {$field->deprecationReason}";
                    }
                }
            },
        ]));

        // Variables: coercible to the declared types, and free of deprecated input fields.
        foreach ($document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode) {
                continue;
            }
            [$errors] = Values::getVariableValues($schema, $definition->variableDefinitions, $variables);
            foreach ($errors ?? [] as $error) {
                $problems[] = $error->getMessage();
            }
            foreach ($definition->variableDefinitions as $variable) {
                $name = $variable->variable->name->value;
                $type = AST::typeFromAST([$schema, 'getType'], $variable->type);
                if ($type !== null && array_key_exists($name, $variables)) {
                    self::findDeprecatedInput($type, $variables[$name], $problems);
                }
            }
        }

        return $problems;
    }

    /**
     * @param list<string> $problems
     */
    private static function findDeprecatedInput(Type $type, mixed $value, array &$problems): void
    {
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }
        if ($type instanceof ListOfType) {
            foreach (is_array($value) ? $value : [] as $item) {
                self::findDeprecatedInput($type->getWrappedType(), $item, $problems);
            }

            return;
        }
        if (!$type instanceof InputObjectType || !is_array($value)) {
            return;
        }
        foreach ($value as $key => $inner) {
            if (!$type->hasField((string) $key)) {
                continue; // already reported by variable coercion
            }
            $field = $type->getField((string) $key);
            $name  = $type->name . '.' . $field->name;
            if ($field->deprecationReason !== null && !in_array($name, self::ALLOWED_DEPRECATIONS, true)) {
                $problems[] = "Deprecated input field {$name}: {$field->deprecationReason}";
            }
            self::findDeprecatedInput($field->getType(), $inner, $problems);
        }
    }

    /**
     * A client whose transport records each operation and answers with just
     * enough data for multi-step methods to reach their later calls.
     */
    private function client(string $version): ShopifyClient
    {
        $handler = function (RequestInterface $request): Response {
            $body         = json_decode((string) $request->getBody(), true);
            $this->sent[] = ['query' => (string) $body['query'], 'variables' => (array) ($body['variables'] ?? [])];

            return new Response(200, [], (string) json_encode(['data' => self::respond((string) $body['query'])]));
        };

        $storage = new GuzzleClient(['handler' => HandlerStack::create(static fn () => \GuzzleHttp\Promise\Create::promiseFor(new Response(204)))]);

        return ShopifyClient::fromGuzzle(
            new GuzzleClient([
                'handler'  => HandlerStack::create(static fn (RequestInterface $r) => \GuzzleHttp\Promise\Create::promiseFor($handler($r))),
                'base_uri' => "https://test.myshopify.com/admin/api/{$version}/",
            ]),
            uploader: $storage,
            downloader: $storage,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function respond(string $query): array
    {
        $media = ['nodes' => [
            ['id' => 'gid://shopify/MediaImage/1', 'alt' => 'KEEP_1.jpg'],
            ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'ORPHAN_1.jpg'],
        ], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];

        return match (true) {
            str_contains($query, 'productCreate(') => ['productCreate' => ['product' => ['id' => 'gid://shopify/Product/1'], 'userErrors' => []]],
            str_contains($query, 'productUpdate(') => ['productUpdate' => [
                'product'    => ['id' => 'gid://shopify/Product/1', 'media' => ['nodes' => [['id' => 'gid://shopify/MediaImage/3', 'alt' => 'NEW_2.jpg']]]],
                'userErrors' => [],
            ]],
            str_contains($query, 'translatableResource(') => ['translatableResource' => ['translatableContent' => [
                ['key' => 'title', 'value' => 'T', 'digest' => 'd1', 'locale' => 'en'],
                ['key' => 'body_html', 'value' => 'B', 'digest' => 'd2', 'locale' => 'en'],
            ]]],
            str_contains($query, 'stagedUploadsCreate(') => ['stagedUploadsCreate' => ['stagedTargets' => [[
                'url' => 'https://storage.test/upload', 'resourceUrl' => 'https://storage.test/res', 'parameters' => [['name' => 'key', 'value' => 'tmp/k']],
            ]], 'userErrors' => []]],
            str_contains($query, 'fulfillmentOrders(') => ['order' => ['fulfillmentOrders' => [
                'nodes' => [['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN']], 'pageInfo' => ['hasNextPage' => false],
            ]]],
            str_contains($query, 'media(') => ['product' => ['media' => $media]],
            str_contains($query, 'node(id:') => ['node' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => 'COMPLETED']],
            default => [],
        };
    }

    private function exerciseEveryMethod(string $version): void
    {
        $c   = $this->client($version);
        $mf  = [Metafield::text('custom', 'erp_id', 'A1'), Metafield::json('custom', 'data', ['a' => 1])];
        $pid = '1';
        $vid = 'gid://shopify/ProductVariant/1';
        $loc = 'gid://shopify/Location/1';
        $inv = 'gid://shopify/InventoryItem/1';
        $fo  = '1';
        $li  = [['id' => 'gid://shopify/FulfillmentOrderLineItem/1', 'quantity' => 1]];

        // Products
        $p = $c->products();
        $p->get($pid);
        $p->search('status:active');
        $p->searchAll('vendor:Acme');
        $p->create(
            ProductInput::make('Tee')->descriptionHtml('<p>x</p>')->vendor('Acme')->productType('Shirts')->handle('tee')
                ->status('DRAFT')->tags(['a'])->metafields($mf)
                ->field(ProductField::title('Tee')->withTranslation('fr', 'T-shirt'))
                ->field(ProductField::seoTitle('Tee SEO'))
                ->field(ProductField::description('<p>x</p>')->withTranslation('fr', '<p>y</p>')),
        );
        $p->update($pid, ProductInput::make('Tee 2')->field(ProductField::title('Tee 2')->withTranslation('es', 'Camiseta')));
        $p->set([
            'title'          => 'Tee',
            'productOptions' => [['name' => 'Size', 'values' => [['name' => 'S']]]],
            'variants'       => [['optionValues' => [['optionName' => 'Size', 'name' => 'S']], 'price' => '9.99', 'inventoryItem' => ['sku' => 'TEE-S']]],
        ]);
        $p->delete($pid);
        $p->setMetafields($pid, $mf);
        $p->media($pid);
        $p->addMedia($pid, [Media::image('https://cdn.test/a.jpg')]);
        $p->ensureMedia($pid, [Media::image('https://cdn.test/b.jpg')]);
        $p->setTranslations($pid, [ProductField::title()->withTranslation('fr', 'Robe')], 'gid://shopify/Market/1');
        $p->findIdBySku('TEE-S');
        $p->reorderMedia($pid, ['gid://shopify/MediaImage/1', 'gid://shopify/MediaImage/2']);
        $p->deleteMedia($pid, ['gid://shopify/MediaImage/1']);
        $p->deleteMedia($pid, ['gid://shopify/MediaImage/1'], deleteFiles: true);
        $p->syncMedia($pid, [
            MediaEntry::url(1, 'https://cdn.test/KEEP_1.jpg'),
            MediaEntry::url(2, 'https://cdn.test/NEW_2.jpg', alt: 'A real description'),
        ], prune: true);

        // Media key store (the map read happens inside the media syncs above)
        $keys = new \Baldie81\ShopifyGraphQL\MediaKeys\MetafieldMediaKeyStore($c->graphql());
        $keys->load('gid://shopify/Product/1');
        $keys->save('gid://shopify/Product/1', ['KEEP_1.jpg' => 'gid://shopify/MediaImage/1']);
        $keys->forget('gid://shopify/Product/1');

        // Variants
        $v = $c->variants();
        $v->list($pid);
        $v->update($pid, [VariantInput::make($vid)->price('9.99')->compareAtPrice(12.0)->sku('S')->barcode('123')->tracked()->cost('4.00')]);
        $v->create($pid, [VariantInput::new()->optionValue('Size', 'M')->price('9.99')->sku('TEE-M')]);
        $v->delete($pid, [$vid]);

        // Inventory
        $i = $c->inventory();
        $i->locations();
        $i->setQuantities([['inventoryItemId' => $inv, 'locationId' => $loc, 'quantity' => 5]]);
        $i->setQuantities([['inventoryItemId' => $inv, 'locationId' => $loc, 'quantity' => 5, 'changeFromQuantity' => 3]]);
        $i->adjustQuantities([['inventoryItemId' => $inv, 'locationId' => $loc, 'delta' => -1]]);
        $i->activate($inv, $loc, 3);

        // Customers
        $cu      = $c->customers();
        $address = Address::make()->firstName('J')->lastName('D')->company('C')->address1('1 St')->address2('Apt')
            ->city('Athens')->provinceCode('I')->countryCode('GR')->zip('10431')->phone('+302101234567');
        $cu->get('1');
        $cu->get('1', true);
        $cu->search('tag:vip');
        $cu->searchAll('tag:vip', withAddresses: true);
        $cu->create(CustomerInput::make('j@example.com')->firstName('J')->lastName('D')->phone('+302101234567')
            ->note('n')->locale('el')->tags(['vip'])->taxExempt()->metafields($mf));
        $cu->update('1', CustomerInput::make()->firstName('Jane'));
        $cu->setMetafields('1', $mf);
        $cu->addresses('1');
        $cu->addAddress('1', $address, true);
        $cu->updateAddress('1', 'gid://shopify/MailingAddress/1', $address);
        $cu->deleteAddress('1', 'gid://shopify/MailingAddress/1');

        // Orders
        $o = $c->orders();
        $o->get('1');
        $o->search('financial_status:paid');
        $o->searchAll();
        $o->fulfill('1', ['number' => 'T1', 'company' => 'ACS', 'url' => 'https://t.test/T1'], true);
        $o->refund('1', ['note' => 'damaged', 'notify' => true]);
        $o->setMetafields('1', $mf);

        // Collections
        $co = $c->collections();
        $co->get('1');
        $co->search('title:Summer');
        $co->searchAll();
        $co->create(CollectionInput::make('Summer')->descriptionHtml('<p>x</p>')->handle('summer')->sortOrder('MANUAL')
            ->templateSuffix('alt')->seo('t', 'd')->image('https://cdn.test/c.jpg', 'alt')->products(['gid://shopify/Product/1'])->metafields($mf));
        $co->create(CollectionInput::make('Smart')->rule('TAG', 'EQUALS', 'sale')->disjunctive());
        $co->update('1', CollectionInput::make('Renamed'));
        $co->delete('1');
        $co->addProducts('1', ['1', '2']);
        $co->removeProducts('1', ['1']);
        $co->reorderProducts('1', [['id' => 'gid://shopify/Product/1', 'newPosition' => '0']]);
        $co->products('1');
        $co->setMetafields('1', $mf);
        $co->job('gid://shopify/Job/1');

        // Fulfillment orders
        $f = $c->fulfillmentOrders();
        $f->get($fo);
        $f->forOrder('1');
        $f->hold($fo, 'OTHER', 'notes', 'handle-1', true, $li);
        $f->releaseHold($fo, ['gid://shopify/FulfillmentHold/1'], 'ext-1');
        $f->move($fo, '1', $li);
        $f->close($fo, 'msg');
        $f->cancel($fo);
        $f->open($fo);
        $f->reschedule($fo, '2027-01-01T00:00:00Z');
        $f->merge([['mergeIntents' => [['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/1', 'fulfillmentOrderLineItems' => $li]]]]);
        $f->split([['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/1', 'fulfillmentOrderLineItems' => $li]]);
        $f->submitFulfillmentRequest($fo, 'msg', $li, true);
        $f->acceptFulfillmentRequest($fo, 'msg');
        $f->rejectFulfillmentRequest($fo, 'msg');
        $f->submitCancellationRequest($fo, 'msg');
        $f->acceptCancellationRequest($fo, 'msg');
        $f->rejectCancellationRequest($fo, 'msg');

        // Price lists
        $pl    = $c->priceLists();
        $price = ['variantId' => '1', 'price' => ['amount' => '9.99', 'currencyCode' => 'EUR'], 'compareAtPrice' => ['amount' => '12.00', 'currencyCode' => 'EUR']];
        $pl->get('1');
        $pl->list();
        $pl->all();
        $pl->create(PriceListInput::make('B2B')->currency('EUR')->catalogId('gid://shopify/CompanyLocationCatalog/1')->adjustment('PERCENTAGE_DECREASE', 10.0));
        $pl->update('1', PriceListInput::make('B2B 2'));
        $pl->delete('1');
        $pl->setPrices('1', [$price]);
        $pl->updatePrices('1', [$price], ['2']);
        $pl->deletePrices('1', ['1']);
        $pl->prices('1');

        // Draft orders
        $d     = $c->draftOrders();
        $draft = DraftOrderInput::make()->lineItem('1', 2)->customLineItem('Setup fee', '25.00')->customLineItem('Rush fee', '5.00', 1, 'EUR')->customer('1')->email('j@example.com')
            ->note('n')->tags(['b2b'])->shippingAddress($address->toArray())->billingAddress($address->toArray())
            ->appliedDiscount(['value' => 10.0, 'valueType' => 'PERCENTAGE', 'title' => 'VIP'])->metafields($mf);
        $d->get('1');
        $d->search('status:open');
        $d->searchAll();
        $d->create($draft);
        $d->create(DraftOrderInput::make()->lineItem('1')->customer('1')->useCustomerDefaultAddress());
        $d->update('1', DraftOrderInput::make()->note('updated'));
        $d->delete('1');
        $d->complete('1', true);
        $d->calculate($draft);
        $d->setMetafields('1', $mf);

        // Gift cards
        $g = $c->giftCards();
        $g->get('1');
        $g->search('status:enabled');
        $g->searchAll();
        $g->create(GiftCardInput::make('50.00', 'EUR')->code('ABCD1234EFGH')->note('n')->expiresOn('2027-12-31')->customer('1')
            ->recipient('2', 'Happy birthday', 'Jo', '2027-01-01T00:00:00Z')->templateSuffix('alt'));
        $g->update('1', ['note' => 'updated']);
        $g->credit('1', '10.00', 'EUR', 'top-up');
        $g->debit('1', 5.0, 'EUR', 'used');
        $g->deactivate('1');

        // Metafield definitions
        $m = $c->metafieldDefinitions();
        $m->define('PRODUCT', 'custom', 'erp_id', MetafieldType::SingleLineText, 'ERP id', ['description' => 'd', 'pin' => true]);
        $m->defineProduct('custom', 'a', 'number_integer', 'A');
        $m->defineOrder('custom', 'b', MetafieldType::Boolean, 'B');
        $m->defineCustomer('custom', 'c', MetafieldType::Json, 'C');
        $m->all('PRODUCT');
        $m->update(['ownerType' => 'PRODUCT', 'namespace' => 'custom', 'key' => 'erp_id', 'name' => 'ERP', 'pin' => false]);
        $m->delete('gid://shopify/MetafieldDefinition/1', true);

        // Translations
        $t = $c->translations();
        $t->translatableContent('gid://shopify/Product/1');
        $t->set('gid://shopify/Product/1', 'fr', ['title' => 'Robe', 'nope' => 'x'], 'gid://shopify/Market/1');
        $t->register('gid://shopify/Product/1', [Translation::make('fr', 'title', 'Robe'), Translation::make('es', 'body_html', 'x', 'd2')->forMarket('gid://shopify/Market/1')]);
        $t->remove('gid://shopify/Product/1', ['title'], ['fr']);

        // Webhooks
        $w = $c->webhooks();
        $w->list();
        $w->all();
        $w->get('1');
        $w->create(WebhookTopic::cases()[0], 'https://app.test/hooks', ['includeFields' => ['id'], 'filter' => 'status:active']);
        $w->update('1', ['uri' => 'https://app.test/hooks2']);
        $w->delete('1');

        // Files
        $fi   = $c->files();
        $file = tempnam(sys_get_temp_dir(), 'sdk') . '.png';
        file_put_contents($file, 'x');
        try {
            $fi->upload($file);
            $fi->uploadMany([$file]);
        } finally {
            @unlink($file);
            @unlink(substr($file, 0, -4));
        }
        $fi->stageJsonl('{"input":{}}');
        $fi->createStagedUploads([['filename' => 'a.png', 'mimeType' => 'image/png', 'httpMethod' => 'POST', 'resource' => 'IMAGE']]);
        $fi->update([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'x', 'originalSource' => 'https://cdn.test/a.jpg']]);
        $fi->delete(['gid://shopify/MediaImage/1']);

        // Bulk operations
        $b = $c->bulkOperations();
        $b->runQuery('{ products { edges { node { id } } } }');
        $b->runMutation('mutation ($input: ProductInput!) { productUpdate(input: $input) { product { id } } }', 'tmp/k');
        $b->importMutation('mutation ($input: ProductInput!) { productUpdate(input: $input) { product { id } } }', [['input' => ['id' => 'gid://shopify/Product/1']]]);
        $b->get('gid://shopify/BulkOperation/1');
        $b->current();
        $b->list('status:running');
        $b->cancel('gid://shopify/BulkOperation/1');
        $b->poll('gid://shopify/BulkOperation/1');
    }
}
