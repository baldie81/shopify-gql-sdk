<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\GiftCardInput;

/**
 * GIFT CARDS — prepaid stored-value instruments (store credit, promos,
 * refund-as-credit). Reached via `$shopify->giftCards()`.
 *
 *   $ex = new GiftCardExamples(ExampleClient::create());
 *
 * Real-life usage: a write method takes **one issuance record** as `$data` (e.g.
 * a promo/loyalty payout from your back office, defaulting to {@see SAMPLE}). The
 * customer id references a Shopify customer; gift-card ids come from Shopify.
 *
 *   foreach ($promo->payouts() as $record) {
 *       $created = $ex->issue($record);
 *       $mailer->send($record['email'], $created['giftCardCode']); // capture the code NOW
 *   }
 *
 * Operations shown in this file:
 *   - issue($data)            — giftCardCreate (returns the one-time code)
 *   - get($id, $fields)       — read one (optional selection override)
 *   - search($query)          — search gift cards (paginated)
 *   - credit($id, $data)      — add funds
 *   - debit($id, $data)       — remove funds
 *   - deactivate($id)         — permanent; there is no delete
 *
 * Critical gotchas:
 *   - The plaintext **code is returned once**, from `issue()` as `giftCardCode` —
 *     capture it then or it's gone (afterwards only `lastCharacters`).
 *   - **No delete** — only `deactivate()` (irreversible).
 *   - Balance changes via `credit()` / `debit()`, never via an update.
 *
 * Related: OrderExamples (refund-as-credit alternative), CustomerExamples.
 */
final class GiftCardExamples
{
    /** One gift-card issuance record (customerId references a Shopify customer). */
    private const SAMPLE = [
        'initialValue' => '25.00',
        'note'         => 'Loyalty reward',
        'expiresOn'    => '2027-12-31',
        'customerId'   => 'gid://shopify/Customer/1',
        'currency'     => 'EUR',
        'creditAmount' => '10.00',
        'debitAmount'  => '5.00',
    ];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Issue a gift card from one record. **Capture `giftCardCode` from the result**
     * — it's the only time the plaintext code is available.
     *
     * @param array<string,mixed> $data an issuance record (defaults to {@see SAMPLE})
     */
    public function issue(array $data = self::SAMPLE): array
    {
        $input = GiftCardInput::make($data['initialValue'])
            ->note($data['note'])
            ->expiresOn($data['expiresOn']);

        if (!empty($data['customerId'])) {
            $input->customer($data['customerId']);
        }

        return $this->shopify->giftCards()->create($input);
    }

    /**
     * Read one gift card. `$fields` overrides the default selection set.
     */
    public function get(string $giftCardId, ?string $fields = null): ?array
    {
        return $this->shopify->giftCards()->get($giftCardId, $fields);
    }

    /**
     * Search gift cards (Shopify query syntax), walking every page.
     */
    public function search(string $query = ''): array
    {
        return $this->shopify->giftCards()->searchAll($query);
    }

    /**
     * Add funds to a card (e.g. a goodwill top-up).
     *
     * @param array<string,mixed> $data uses its `creditAmount` + `currency`
     */
    public function credit(string $giftCardId, array $data = self::SAMPLE): array
    {
        return $this->shopify->giftCards()->credit($giftCardId, $data['creditAmount'], $data['currency'], 'Top up');
    }

    /**
     * Remove funds from a card.
     *
     * @param array<string,mixed> $data uses its `debitAmount` + `currency`
     */
    public function debit(string $giftCardId, array $data = self::SAMPLE): array
    {
        return $this->shopify->giftCards()->debit($giftCardId, $data['debitAmount'], $data['currency']);
    }

    /**
     * Permanently deactivate a card (no delete, no reactivation).
     */
    public function deactivate(string $giftCardId): array
    {
        return $this->shopify->giftCards()->deactivate($giftCardId);
    }
}
