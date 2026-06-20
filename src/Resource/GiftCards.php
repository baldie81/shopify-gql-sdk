<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Support\Gid;
use Baldie81\ShopifyGraphQL\Support\GiftCardInput;

/**
 * Gift cards — prepaid stored-value instruments. Issue them directly (store
 * credit, promos, refund-as-credit), adjust the balance, and deactivate.
 *
 * Two things to remember (see {@see create()} / {@see deactivate()}):
 *   - the plaintext code is returned **once**, from `create()` as `giftCardCode`;
 *   - gift cards can't be deleted — only **deactivated** (irreversible).
 *
 * Balance is changed via {@see credit()} / {@see debit()}, never via {@see update()}.
 */
final class GiftCards extends AbstractResource
{
    private const string FIELDS = 'id balance { amount currencyCode } initialValue { amount currencyCode }'
        . ' enabled expiresOn lastCharacters note createdAt customer { id email }';

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id, ?string $fields = null): ?array
    {
        $query = 'query ($id: ID!) { giftCard(id: $id) { ' . ($fields ?? self::FIELDS) . ' } }';

        return $this->gql->execute($query, ['id' => Gid::giftCard($id)])['giftCard'] ?? null;
    }

    /**
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function search(string $query = '', int $first = 50, ?string $after = null, ?string $fields = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery($fields), ['first' => $first, 'after' => $after, 'query' => $query]);

        return $data['giftCards'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchAll(string $query = '', int $pageSize = self::MAX_PAGE_SIZE, ?string $fields = null): array
    {
        return $this->paginateNodes(self::listQuery($fields), ['query' => $query], 'giftCards', $pageSize);
    }

    /**
     * Issue a gift card. The result includes `giftCardCode` — the **only** time
     * the plaintext code is returned, so capture it here if you need to deliver it.
     *
     * @param GiftCardInput|array<string,mixed> $input
     * @return array{giftCard:?array<string,mixed>,giftCardCode:?string,userErrors:list<array<string,mixed>>}
     */
    public function create(GiftCardInput|array $input): array
    {
        $mutation = 'mutation ($input: GiftCardCreateInput!) {'
            . ' giftCardCreate(input: $input) { giftCard { ' . self::FIELDS . ' } giftCardCode userErrors { field message code } } }';

        return $this->gql->execute($mutation, ['input' => $this->payload($input)])['giftCardCreate'] ?? ['giftCard' => null, 'giftCardCode' => null, 'userErrors' => []];
    }

    /**
     * Update gift-card metadata (note, expiry, customer, template) — NOT balance.
     *
     * @param array<string,mixed> $input a GiftCardUpdateInput
     * @return array{giftCard:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function update(string $id, array $input): array
    {
        $mutation = 'mutation ($id: ID!, $input: GiftCardUpdateInput!) {'
            . ' giftCardUpdate(id: $id, input: $input) { giftCard { ' . self::FIELDS . ' } userErrors { field message code } } }';

        return $this->gql->execute($mutation, ['id' => Gid::giftCard($id), 'input' => $input])['giftCardUpdate'] ?? ['giftCard' => null, 'userErrors' => []];
    }

    /**
     * Add funds (`giftCardCredit`).
     *
     * @return array{giftCardCreditTransaction:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function credit(string $id, string|float $amount, string $currencyCode, ?string $note = null): array
    {
        $mutation = 'mutation ($id: ID!, $creditInput: GiftCardCreditInput!) {'
            . ' giftCardCredit(id: $id, creditInput: $creditInput) {'
            . ' giftCardCreditTransaction { id amount { amount currencyCode } } userErrors { field message code } } }';

        $creditInput = ['creditAmount' => self::money($amount, $currencyCode)];
        if ($note !== null) {
            $creditInput['note'] = $note;
        }

        return $this->gql->execute($mutation, ['id' => Gid::giftCard($id), 'creditInput' => $creditInput])['giftCardCredit'] ?? ['giftCardCreditTransaction' => null, 'userErrors' => []];
    }

    /**
     * Remove funds (`giftCardDebit`).
     *
     * @return array{giftCardDebitTransaction:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function debit(string $id, string|float $amount, string $currencyCode, ?string $note = null): array
    {
        $mutation = 'mutation ($id: ID!, $debitInput: GiftCardDebitInput!) {'
            . ' giftCardDebit(id: $id, debitInput: $debitInput) {'
            . ' giftCardDebitTransaction { id amount { amount currencyCode } } userErrors { field message code } } }';

        $debitInput = ['debitAmount' => self::money($amount, $currencyCode)];
        if ($note !== null) {
            $debitInput['note'] = $note;
        }

        return $this->gql->execute($mutation, ['id' => Gid::giftCard($id), 'debitInput' => $debitInput])['giftCardDebit'] ?? ['giftCardDebitTransaction' => null, 'userErrors' => []];
    }

    /**
     * Deactivate a gift card (`giftCardDeactivate`) — permanent; there is no delete
     * and no reactivation.
     *
     * @return array{giftCard:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function deactivate(string $id): array
    {
        $mutation = 'mutation ($id: ID!) { giftCardDeactivate(id: $id) { giftCard { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::giftCard($id)])['giftCardDeactivate'] ?? ['giftCard' => null, 'userErrors' => []];
    }

    /**
     * @return array{amount:string,currencyCode:string}
     */
    private static function money(string|float $amount, string $currencyCode): array
    {
        return ['amount' => number_format((float) $amount, 2, '.', ''), 'currencyCode' => $currencyCode];
    }

    private static function listQuery(?string $fields): string
    {
        return 'query ($first: Int!, $after: String, $query: String) {'
            . ' giftCards(first: $first, after: $after, query: $query) {'
            . ' nodes { ' . ($fields ?? self::FIELDS) . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
