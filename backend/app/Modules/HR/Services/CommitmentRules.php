<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use Illuminate\Support\Facades\DB;

/**
 * The school's commitment rules, stored in its settings. For each measure (punctuality, daily summaries, weekly plans): a score
 * from 0 to 100; at or above `bonus_min` the person earns a bonus, below `deduction_below` they lose an amount. An amount is a
 * percent of the base salary or a fixed sum. Everything is off until the school switches it on.
 */
class CommitmentRules
{
    public const CODES = ['punctuality', 'daily_summary', 'weekly_plan'];

    private const RULE = ['enabled' => false, 'bonus_min' => 90, 'bonus_type' => 'amount', 'bonus_value' => 0, 'deduction_below' => 70, 'deduction_type' => 'percent', 'deduction_value' => 0];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = DB::table('school_settings')->where('key', 'payroll_rules')->value('value');
        $saved = $stored !== null ? (json_decode($stored, true) ?: []) : [];
        $out = ['grace_minutes' => (int) ($saved['grace_minutes'] ?? 10)];
        foreach (self::CODES as $code) {
            $out[$code] = array_merge(self::RULE, $saved[$code] ?? []);
        }

        return $out;
    }

    public function graceMinutes(): int
    {
        return $this->all()['grace_minutes'];
    }

    /** @param  array<string, mixed>  $rules */
    public function save(array $rules): void
    {
        $clean = ['grace_minutes' => (int) ($rules['grace_minutes'] ?? 10)];
        foreach (self::CODES as $code) {
            $r = array_merge(self::RULE, $rules[$code] ?? []);
            $clean[$code] = [
                'enabled' => (bool) $r['enabled'], 'bonus_min' => (int) $r['bonus_min'], 'bonus_type' => $r['bonus_type'], 'bonus_value' => (float) $r['bonus_value'],
                'deduction_below' => (int) $r['deduction_below'], 'deduction_type' => $r['deduction_type'], 'deduction_value' => (float) $r['deduction_value'],
            ];
        }
        DB::table('school_settings')->updateOrInsert(['key' => 'payroll_rules'], ['value' => json_encode($clean), 'created_at' => now(), 'updated_at' => now()]);
    }
}
