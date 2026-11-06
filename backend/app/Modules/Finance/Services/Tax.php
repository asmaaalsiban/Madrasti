<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\StudentFee;
use Illuminate\Support\Facades\DB;

/**
 * The school's tax (VAT / sales tax) settings and the tax inside a fee. Off by default, so nothing changes until a school turns it on.
 * Prices already include the tax: a taxable amount of 116 at 16% holds 16 of tax.
 */
class Tax
{
    public const DEFAULTS = ['enabled' => false, 'name' => 'VAT', 'rate' => 0, 'number' => '', 'fee_kinds' => []];

    /** @return array{enabled: bool, name: string, rate: float|int, number: string, fee_kinds: list<string>} */
    public function settings(): array
    {
        $v = DB::table('school_settings')->where('key', 'tax')->value('value');

        return array_merge(self::DEFAULTS, $v ? (array) json_decode((string) $v, true) : []);
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        DB::table('school_settings')->updateOrInsert(['key' => 'tax'], ['value' => json_encode(array_intersect_key($data, self::DEFAULTS)), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** The tax inside a price that already includes it. */
    public function inside(int $grossCents, float $rate): int
    {
        return $rate > 0 ? (int) round($grossCents * $rate / (100 + $rate)) : 0;
    }

    /** The tax held by a fee: only the kinds the school chose, after the discount. */
    public function ofFee(StudentFee $fee): int
    {
        $s = $this->settings();
        if (! $s['enabled'] || (float) $s['rate'] <= 0 || $s['fee_kinds'] === []) {
            return 0;
        }
        $total = Money::cents($fee->total_amount);
        if ($total <= 0) {
            return 0;
        }
        $taxable = (int) $fee->items()->get()->filter(fn ($i) => in_array($i->kind, $s['fee_kinds'], true) && Money::cents($i->amount) > 0)->sum(fn ($i) => Money::cents($i->amount));
        $net = $total - Money::cents($fee->discount);

        return $this->inside((int) round($taxable * $net / $total), (float) $s['rate']);
    }
}
