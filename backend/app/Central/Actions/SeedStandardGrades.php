<?php

declare(strict_types=1);

namespace App\Central\Actions;

use App\Modules\Academic\Models\GradeLevel;

/**
 * Makes sure the school has the standard levels, in order: buds, KG1, KG2 and grades 1 to 12 (grade 12 = Tawjihi).
 * An existing level is matched by its English name: it keeps the name the school gave it and only gets its code and place.
 * Runs inside the school (tenant) database.
 */
class SeedStandardGrades
{
    private const ARABIC_ORDINALS = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس', 'السابع', 'الثامن', 'التاسع', 'العاشر', 'الحادي عشر', 'الثاني عشر (التوجيهي)'];

    /**
     * @return int how many levels were created
     */
    public function handle(): int
    {
        $levels = [['Buds', 'براعم', 'BUD'], ['KG1', 'الروضة الأولى', 'KG1'], ['KG2', 'الروضة الثانية', 'KG2']];
        foreach (self::ARABIC_ORDINALS as $i => $ordinal) {
            $levels[] = ['Grade '.($i + 1), 'الصف '.$ordinal, (string) ($i + 1)];
        }

        $created = 0;
        foreach ($levels as $i => [$nameEn, $nameAr, $code]) {
            $level = GradeLevel::firstOrNew(['name_en' => $nameEn]);
            $created += $level->exists ? 0 : 1;
            $level->name_ar = $level->exists ? $level->name_ar : $nameAr;
            $level->fill(['code' => $code, 'sort_order' => $i + 1]);
            if (in_array($code, ['BUD', 'KG1', 'KG2', '1', '2', '3'], true) && $level->periods_per_day === null) {
                $level->periods_per_day = 6; // the kindergarten and grades 1-3 have a shorter day; a value the school chose is kept
            }
            $level->save();
        }

        return $created;
    }
}
