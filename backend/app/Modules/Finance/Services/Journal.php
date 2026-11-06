<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Posts balanced entries and keeps the books locked up to a date. */
class Journal
{
    public function lockedUntil(): ?string
    {
        $v = DB::table('school_settings')->where('key', 'books_locked_until')->value('value');

        return $v ? (string) json_decode((string) $v) : null;
    }

    public function lock(?string $date): void
    {
        DB::table('school_settings')->updateOrInsert(
            ['key' => 'books_locked_until'],
            ['value' => json_encode($date), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function assertOpen(string $date): void
    {
        $locked = $this->lockedUntil();
        if ($locked && $date <= $locked) {
            throw ValidationException::withMessages(['entry_date' => [__('finance.books_locked', ['date' => $locked])]]);
        }
    }

    /**
     * @param  list<array{ledger_account_id:int, debit?:string|float|int|null, credit?:string|float|int|null, note?:?string}>  $lines
     */
    public function post(string $date, string $memo, array $lines, ?User $by = null, ?string $sourceType = null, ?int $sourceId = null): JournalEntry
    {
        $this->assertOpen($date);
        $debit = 0;
        $credit = 0;
        foreach ($lines as $l) {
            $d = Money::cents($l['debit'] ?? 0);
            $c = Money::cents($l['credit'] ?? 0);
            if (($d > 0) === ($c > 0)) { // both or neither
                throw ValidationException::withMessages(['lines' => [__('finance.line_one_side')]]);
            }
            $debit += $d;
            $credit += $c;
        }
        if (count($lines) < 2 || $debit !== $credit) {
            throw ValidationException::withMessages(['lines' => [__('finance.entry_unbalanced')]]);
        }

        return DB::transaction(function () use ($date, $memo, $lines, $by, $sourceType, $sourceId): JournalEntry {
            $entry = JournalEntry::create(['entry_date' => $date, 'memo' => $memo, 'source_type' => $sourceType, 'source_id' => $sourceId, 'created_by' => $by?->id]);
            foreach ($lines as $l) {
                $entry->lines()->create(['ledger_account_id' => $l['ledger_account_id'], 'debit' => ($l['debit'] ?? 0) ?: 0, 'credit' => ($l['credit'] ?? 0) ?: 0, 'note' => $l['note'] ?? null]);
            }

            return $entry;
        });
    }
}
