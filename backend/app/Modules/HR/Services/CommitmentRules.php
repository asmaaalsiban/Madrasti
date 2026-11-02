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


    private const ABSENCE = ['enabled' => false, 'type' => 'amount', 'value' => 0];

    /**
     * The rules with every measure complete. Old rules (one deduction threshold) are read as a single tier.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = DB::table('school_settings')->where('key', 'payroll_rules')->value('value');
        $saved = $stored !== null ? (json_decode($stored, true) ?: []) : [];
        $out = ['grace_minutes' => (int) ($saved['grace_minutes'] ?? 10)];
        foreach (self::CODES as $code) {
            $rule = array_merge(self::RULE, $saved[$code] ?? []);
            if (! isset($rule['deduction_tiers'])) {
                $rule['deduction_tiers'] = (float) $rule['deduction_value'] > 0 ? [['below' => (int) $rule['deduction_below'], 'type' => $rule['deduction_type'], 'value' => (float) $rule['deduction_value']]] : [];
            }
            unset($rule['deduction_below'], $rule['deduction_type'], $rule['deduction_value']);
            $out[$code] = $rule;
        }
        $out['absence'] = array_merge(self::ABSENCE, $saved['absence'] ?? []);

        return $out;
    }

    public function graceMinutes(): int
    {
        return $this->all()['grace_minutes'];
    }

    /** @param  array<string, mixed>  $rules */
    public function save(array $rules): void
    {
        $current = $this->all();
        $clean = ['grace_minutes' => (int) ($rules['grace_minutes'] ?? $current['grace_minutes'])];
        foreach (self::CODES as $code) {
            $r = array_merge($current[$code], $rules[$code] ?? []);
            // The old single deduction (below / type / value) still works as one tier.
            if (isset($rules[$code]) && ! isset($rules[$code]['deduction_tiers']) && isset($rules[$code]['deduction_value'])) {
                $r['deduction_tiers'] = [['below' => (int) ($rules[$code]['deduction_below'] ?? 70), 'type' => $rules[$code]['deduction_type'] ?? 'percent', 'value' => (float) $rules[$code]['deduction_value']]];
            }
            $tiers = collect($r['deduction_tiers'])->map(fn ($t) => ['below' => (int) $t['below'], 'type' => $t['type'], 'value' => (float) $t['value']])->filter(fn ($t) => $t['value'] > 0)->sortByDesc('below')->values()->all();
            $clean[$code] = ['enabled' => (bool) $r['enabled'], 'bonus_min' => (int) $r['bonus_min'], 'bonus_type' => $r['bonus_type'], 'bonus_value' => (float) $r['bonus_value'], 'deduction_tiers' => $tiers];
        }
        $a = array_merge($current['absence'], $rules['absence'] ?? []);
        $clean['absence'] = ['enabled' => (bool) $a['enabled'], 'type' => $a['type'], 'value' => (float) $a['value']];
        DB::table('school_settings')->updateOrInsert(['key' => 'payroll_rules'], ['value' => json_encode($clean), 'created_at' => now(), 'updated_at' => now()]);
    }
}
