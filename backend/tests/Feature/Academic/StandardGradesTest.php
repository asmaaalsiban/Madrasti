<?php

namespace Tests\Feature\Academic;

use App\Central\Actions\SeedStandardGrades;
use App\Modules\Academic\Models\GradeLevel;
use Tests\TenantTestCase;

class StandardGradesTest extends TenantTestCase
{
    public function test_it_creates_the_fifteen_levels_from_buds_to_tawjihi_in_order(): void
    {
        app(SeedStandardGrades::class)->handle();

        $levels = GradeLevel::orderBy('sort_order')->get();
        $this->assertCount(15, $levels);
        $this->assertSame(['BUD', 'KG1', 'KG2', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'], $levels->pluck('code')->all());
        $this->assertSame('براعم', $levels->first()->name_ar);
        $this->assertSame(range(1, 15), $levels->pluck('sort_order')->all());
        $this->assertStringContainsString('التوجيهي', $levels->last()->name_ar);
    }

    public function test_it_reuses_existing_levels_by_english_name_and_is_safe_to_repeat(): void
    {
        GradeLevel::create(['name_ar' => 'صف ثالث', 'name_en' => 'Grade 3', 'sort_order' => 3]);

        app(SeedStandardGrades::class)->handle();
        app(SeedStandardGrades::class)->handle();

        $this->assertSame(15, GradeLevel::count());
        $third = GradeLevel::where('name_en', 'Grade 3')->first();
        $this->assertSame('3', $third->code);
        $this->assertSame(6, $third->sort_order, 'moved into its place after the kindergarten levels');
        $this->assertSame('صف ثالث', $third->name_ar, 'the name the school chose is kept');
    }
}
