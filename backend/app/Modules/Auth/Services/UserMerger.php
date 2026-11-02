<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folds one account into another (the same person registered twice): everything that pointed at the old account now
 * points at the kept one, then the old account is removed. Works over every table with a foreign key to users, so a new
 * table is covered without touching this class. Rows that would then duplicate a unique row of the kept account are dropped.
 */
class UserMerger
{
    public function merge(User $from, User $into): void
    {
        DB::transaction(function () use ($from, $into): void {
            foreach (Schema::getTables() as $table) {
                foreach (Schema::getForeignKeys($table['name']) as $key) {
                    if ($key['foreign_table'] === 'users' && count($key['columns']) === 1) {
                        $this->move($table['name'], $key['columns'][0], $from->id, $into->id);
                    }
                }
            }

            // Notifications and tokens point at users without a foreign key.
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $from->id)->update(['notifiable_id' => $into->id]);
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $from->id)->delete();

            $phone = $from->phone;
            $password = $from->getRawOriginal('password');
            User::withoutEvents(fn () => $from->forceDelete()); // no audit row: it would point at the account being removed

            // The person's last choices win: their number (if the kept account has none) and the password they just chose.
            DB::table('users')->where('id', $into->id)->update(array_filter([
                'phone' => blank($into->phone) ? $phone : null,
                'password' => $password,
                'must_change_password' => false,
                'updated_at' => now(),
            ], fn ($v) => $v !== null));
        });
    }

    private function move(string $table, string $column, int $from, int $into): void
    {
        // A child linked to both accounts: the link of the old one is dropped, but a "primary parent" flag is kept.
        if ($table === 'guardian_student') {
            DB::table($table)->where($column, $from)->where('is_primary', true)
                ->whereIn('student_id', DB::table($table)->where($column, $into)->select('student_id'))
                ->get()->each(fn ($row) => DB::table($table)->where($column, $into)->where('student_id', $row->student_id)->update(['is_primary' => true]));
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (! ($index['unique'] || $index['primary']) || ! in_array($column, $index['columns'], true)) {
                continue;
            }
            $others = array_values(array_diff($index['columns'], [$column]));
            $duplicates = DB::table($table)->where($column, $from)->whereExists(function ($q) use ($table, $column, $into, $others) {
                $q->from($table, 'kept')->where("kept.$column", $into);
                foreach ($others as $other) {
                    $q->whereColumn("kept.$other", "$table.$other");
                }
            });
            $duplicates->delete();
        }

        DB::table($table)->where($column, $from)->update([$column => $into]);
    }
}
