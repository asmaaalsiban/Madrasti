<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

/** Money is added and compared in cents (integers) so rounding never drifts; the database keeps two decimals. */
final class Money
{
    public static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
