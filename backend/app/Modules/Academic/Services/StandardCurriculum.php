<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

/**
 * The starting curriculum offered to a school for grades 1 to 10: 35 periods a week (7 periods x 5 days), Arabic, English
 * and maths every day, one science subject up to grade 8 then physics, chemistry, biology and earth science, social
 * studies until grade 6 then history, geography and national education. It is a template: the admin edits it afterwards.
 */
final class StandardCurriculum
{
    /** code => [Arabic name, English name] */
    public const SUBJECTS = [
        'ARB' => ['اللغة العربية', 'Arabic'], 'ENG' => ['اللغة الإنجليزية', 'English'], 'MTH' => ['الرياضيات', 'Math'],
        'SCI' => ['العلوم', 'Science'], 'REL' => ['التربية الإسلامية', 'Islamic Studies'], 'SOC' => ['الاجتماعيات', 'Social Studies'],
        'HIS' => ['التاريخ', 'History'], 'GEO' => ['الجغرافيا', 'Geography'], 'NAT' => ['التربية الوطنية', 'National Education'],
        'CMP' => ['الحاسوب', 'Computer'], 'PE' => ['التربية الرياضية', 'Physical Education'], 'FIN' => ['الثقافة المالية', 'Financial Literacy'],
        'VOC' => ['التربية المهنية', 'Vocational Education'], 'ART' => ['التربية الفنية', 'Art'], 'MUS' => ['التربية الموسيقية', 'Music'],
        'ACT' => ['الأنشطة', 'Activities'], 'PHY' => ['الفيزياء', 'Physics'], 'CHM' => ['الكيمياء', 'Chemistry'],
        'BIO' => ['الأحياء', 'Biology'], 'ESC' => ['علوم الأرض', 'Earth Science'],
    ];

    /**
     * @return array<string, array<string, int>> grade code => subject code => periods per week
     */
    public static function plan(): array
    {
        $common = ['ENG' => 5, 'MTH' => 5, 'REL' => 3, 'CMP' => 2, 'PE' => 2];
        $plan = [];
        foreach (['1', '2', '3'] as $g) {
            $plan[$g] = $common + ['ARB' => 7, 'SCI' => 5, 'SOC' => 2, 'ART' => 1, 'MUS' => 1, 'ACT' => 2];
        }
        foreach (['4', '5', '6'] as $g) {
            $plan[$g] = $common + ['ARB' => 6, 'SCI' => 5, 'SOC' => 2, 'ART' => 1, 'MUS' => 1, 'VOC' => 1, 'ACT' => 2];
        }
        foreach (['7', '8'] as $g) {
            $plan[$g] = $common + ['ARB' => 6, 'SCI' => 5, 'HIS' => 1, 'GEO' => 1, 'NAT' => 1, 'FIN' => 1, 'VOC' => 2, 'ART' => 1];
        }
        foreach (['9', '10'] as $g) {
            $plan[$g] = $common + ['ARB' => 5, 'PHY' => 2, 'CHM' => 2, 'BIO' => 2, 'ESC' => 2, 'HIS' => 1, 'GEO' => 1, 'NAT' => 1, 'FIN' => 1, 'VOC' => 1];
        }

        return $plan;
    }
}
