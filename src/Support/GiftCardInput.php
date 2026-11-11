<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Fluent builder for a `GiftCardCreateInput`.
 *
 *   GiftCardInput::make('50.00')->customer($customerId)->note('Loyalty reward')->expiresOn('2027-12-31');
 *   GiftCardInput::make('50.00', 'USD')->recipient($friendId, message: 'Happy birthday!');
 *
 * The amount is sent as `initialAmount` (a `MoneyInput`), which replaces the
 * deprecated `initialValue`. The currency defaults to {@see DEFAULT_CURRENCY}.
 * Omit `code()` to let Shopify generate one — and remember the plaintext code is
 * only returned once, from `giftCardCreate` (as `giftCardCode`).
 */
final class GiftCardInput implements Arrayable
{
    public const string DEFAULT_CURRENCY = 'EUR';

    /** @var array<string,mixed> */
    private array $fields = [];

    /**
     * @param string $currencyCode ISO 4217 code, e.g. `EUR`, `USD`
     */
    public static function make(string|float $amount, string $currencyCode = self::DEFAULT_CURRENCY): self
    {
        return (new self())->initialAmount($amount, $currencyCode);
    }

    /**
     * @param string $currencyCode ISO 4217 code, e.g. `EUR`, `USD`
     */
    public function initialAmount(string|float $amount, string $currencyCode = self::DEFAULT_CURRENCY): self
    {
        $this->fields['initialAmount'] = [
            'amount'       => self::amount($amount),
            'currencyCode' => strtoupper($currencyCode),
        ];

        return $this;
    }

    /**
     * A custom code (8–20 alphanumeric chars). Omit to auto-generate.
     */
    public function code(string $code): self
    {
        $this->fields['code'] = $code;

        return $this;
    }

    /**
     * Internal note (not shown to the customer).
     */
    public function note(string $note): self
    {
        $this->fields['note'] = $note;

        return $this;
    }

    /**
     * Expiry date (YYYY-MM-DD). Some regions disallow expiry.
     */
    public function expiresOn(string $date): self
    {
        $this->fields['expiresOn'] = $date;

        return $this;
    }

    public function customer(string $customerId): self
    {
        $this->fields['customerId'] = Gid::customer($customerId);

        return $this;
    }

    /**
     * Who receives the gift card (as opposed to {@see customer()}, who it's issued
     * to). Shopify emails the recipient the gift card notification, optionally with
     * a personal message, a preferred name, and at a scheduled time.
     *
     * @param string|null $sendAt ISO 8601 date-time; omit to notify immediately
     */
    public function recipient(
        string $customerId,
        ?string $message = null,
        ?string $preferredName = null,
        ?string $sendAt = null,
    ): self {
        $recipient = ['id' => Gid::customer($customerId)];
        if ($message !== null) {
            $recipient['message'] = $message;
        }
        if ($preferredName !== null) {
            $recipient['preferredName'] = $preferredName;
        }
        if ($sendAt !== null) {
            $recipient['sendNotificationAt'] = $sendAt;
        }

        $this->fields['recipientAttributes'] = $recipient;

        return $this;
    }

    /**
     * Theme template for the gift card's online page: `'birthday'` renders it with
     * `templates/gift_card.birthday.liquid`. Does not affect the notification email.
     */
    public function templateSuffix(string $templateSuffix): self
    {
        $this->fields['templateSuffix'] = $templateSuffix;

        return $this;
    }

    public function set(string $field, mixed $value): self
    {
        $this->fields[$field] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->fields;
    }

    private static function amount(string|float $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
