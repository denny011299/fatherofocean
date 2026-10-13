<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;

final class InvoicePaymentSettings
{
    public const KEY_BANK = 'invoice_payment_bank';

    public const KEY_ACCOUNT_NAME = 'invoice_payment_account_name';

    public const KEY_ACCOUNT_NUMBER = 'invoice_payment_account_number';

    /**
     * @return array{bank: string, account_name: string, account_number: string}
     */
    public static function current(): array
    {
        return [
            'bank' => self::normalize(SystemSetting::getValue(self::KEY_BANK)),
            'account_name' => self::normalize(SystemSetting::getValue(self::KEY_ACCOUNT_NAME)),
            'account_number' => self::normalize(SystemSetting::getValue(self::KEY_ACCOUNT_NUMBER)),
        ];
    }

    /**
     * @return array{bank: string, account_name: string, account_number: string}
     */
    public static function update(string $bank, string $accountName, string $accountNumber): array
    {
        SystemSetting::setValue(self::KEY_BANK, self::normalize($bank));
        SystemSetting::setValue(self::KEY_ACCOUNT_NAME, self::normalize($accountName));
        SystemSetting::setValue(self::KEY_ACCOUNT_NUMBER, self::normalize($accountNumber));

        return self::current();
    }

    private static function normalize(?string $value): string
    {
        return trim((string) $value);
    }
}
