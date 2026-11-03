<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use Illuminate\Support\Facades\DB;

/**
 * Pay by bank transfer / CliQ: the school's account details and instructions, shown to the parent, who transfers the money and sends the
 * proof; the accountant confirms. No secrets here (these are the details a school gives to parents anyway).
 */
class BankTransferSettings
{
    /** @return array{enabled: bool, instructions: string, accounts: list<array{bank: string, holder: string, iban: string, cliq: string}>} */
    public function get(): array
    {
        $raw = DB::table('school_settings')->where('key', 'bank_transfer')->value('value');
        $data = $raw ? (json_decode((string) $raw, true) ?: []) : [];

        return [
            'enabled' => (bool) ($data['enabled'] ?? false), 'instructions' => (string) ($data['instructions'] ?? ''),
            'accounts' => array_values(array_map(fn ($a) => ['bank' => (string) ($a['bank'] ?? ''), 'holder' => (string) ($a['holder'] ?? ''), 'iban' => (string) ($a['iban'] ?? ''), 'cliq' => (string) ($a['cliq'] ?? '')], $data['accounts'] ?? [])),
        ];
    }

    /** On, with at least one account to transfer to. */
    public function enabled(): bool
    {
        $s = $this->get();

        return $s['enabled'] && $s['accounts'] !== [];
    }

    /** @param  array{enabled: bool, instructions?: string|null, accounts: list<array<string, string|null>>}  $data */
    public function save(array $data): void
    {
        $clean = ['enabled' => (bool) $data['enabled'], 'instructions' => (string) ($data['instructions'] ?? ''), 'accounts' => array_values(array_map(fn ($a) => [
            'bank' => (string) ($a['bank'] ?? ''), 'holder' => (string) ($a['holder'] ?? ''), 'iban' => (string) ($a['iban'] ?? ''), 'cliq' => (string) ($a['cliq'] ?? ''),
        ], $data['accounts']))];
        DB::table('school_settings')->updateOrInsert(['key' => 'bank_transfer'], ['value' => json_encode($clean, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]);
    }
}
