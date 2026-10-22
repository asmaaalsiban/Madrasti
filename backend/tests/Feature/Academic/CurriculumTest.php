<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class CurriculumTest extends AcademicTestCase
{
    private GradeLevel $grade1;

    private GradeLevel $grade7;

    private Subject $math;

    private Subject $science;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grade1 = GradeLevel::factory()->create(['name_en' => 'Grade 1', 'code' => '1', 'sort_order' => 4]);
        $this->grade7 = GradeLevel::factory()->create(['name_en' => 'Grade 7', 'code' => '7', 'sort_order' => 10]);
        $this->math = Subject::factory()->create(['code' => 'MTH']);
        $this->science = Subject::factory()->create(['code' => 'SCI']);
    }

    private function cell(GradeLevel $grade, Subject $subject, ?int $periods): array
    {
        return ['grade_level_id' => $grade->id, 'subject_id' => $subject->id, 'weekly_periods' => $periods];
    }

    public function test_the_curriculum_lists_grades_subjects_cells_totals_and_the_weekly_periods_of_the_school(): void
    {
        $this->actAsAdmin();
        DB::table('grade_level_subjects')->insert([
            [...$this->cell($this->grade1, $this->math, 5), 'created_at' => now(), 'updated_at' => now()],
            [...$this->cell($this->grade1, $this->science, 4), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $data = $this->getJson('/api/curriculum')->assertOk()->json('data');

        $this->assertSame(35, $data['periods_per_week'], 'the default week has 7 periods x 5 days');
        $this->assertSame([$this->grade1->id, $this->grade7->id], collect($data['grades'])->pluck('id')->all(), 'in the order of the grades');
        $this->assertCount(2, $data['subjects']);
        $this->assertCount(2, $data['cells']);
        $this->assertSame(9, $data['totals'][(string) $this->grade1->id]);
        $this->assertSame(0, $data['totals'][(string) $this->grade7->id] ?? 0);
    }

    public function test_saving_adds_changes_and_removes_cells_and_stores_the_weekly_periods(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/curriculum', ['periods_per_week' => 30, 'cells' => [$this->cell($this->grade1, $this->math, 5), $this->cell($this->grade1, $this->science, 4)]])->assertOk();
        $this->assertSame(2, DB::table('grade_level_subjects')->count());

        $this->putJson('/api/curriculum', ['cells' => [$this->cell($this->grade1, $this->math, 6), $this->cell($this->grade7, $this->math, 5)]])->assertOk();

        $rows = DB::table('grade_level_subjects')->get();
        $this->assertSame(2, $rows->count(), 'the science cell is gone, the grade 7 one is new');
        $this->assertSame(6, (int) $rows->firstWhere('grade_level_id', $this->grade1->id)->weekly_periods);
        $this->assertSame(30, $this->getJson('/api/curriculum')->json('data.periods_per_week'), 'kept when not sent again');
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => GradeLevelSubject::class, 'action' => 'created']);
    }

    public function test_empty_or_zero_periods_mean_the_subject_is_not_taught(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/curriculum', ['cells' => [$this->cell($this->grade1, $this->math, 5)]])->assertOk();

        $this->putJson('/api/curriculum', ['cells' => [$this->cell($this->grade1, $this->math, 0), $this->cell($this->grade7, $this->science, null)]])->assertOk();

        $this->assertSame(0, DB::table('grade_level_subjects')->count());
    }

    public function test_the_cells_are_validated(): void
    {
        $this->actAsAdmin();
        $put = fn (array $cells, array $extra = []) => $this->putJson('/api/curriculum', ['cells' => $cells, ...$extra]);

        $put([$this->cell($this->grade1, $this->math, 41)])->assertUnprocessable()->assertJsonValidationErrors('cells.0.weekly_periods');
        $put([$this->cell($this->grade1, $this->math, -1)])->assertUnprocessable();
        $put([['grade_level_id' => 999, 'subject_id' => $this->math->id, 'weekly_periods' => 2]])->assertUnprocessable()->assertJsonValidationErrors('cells.0.grade_level_id');
        $put([['grade_level_id' => $this->grade1->id, 'subject_id' => 999, 'weekly_periods' => 2]])->assertUnprocessable()->assertJsonValidationErrors('cells.0.subject_id');
        $put([$this->cell($this->grade1, $this->math, 2), $this->cell($this->grade1, $this->math, 3)])->assertUnprocessable();
        $put([], ['periods_per_week' => 3])->assertUnprocessable()->assertJsonValidationErrors('periods_per_week');
        $this->putJson('/api/curriculum', [])->assertUnprocessable()->assertJsonValidationErrors('cells');
    }

    public function test_one_grade_can_be_copied_to_others_replacing_what_they_had(): void
    {
        $this->actAsAdmin();
        $grade8 = GradeLevel::factory()->create(['code' => '8', 'sort_order' => 11]);
        $this->putJson('/api/curriculum', ['cells' => [
            $this->cell($this->grade1, $this->math, 5), $this->cell($this->grade1, $this->science, 4), $this->cell($this->grade7, $this->math, 2),
        ]])->assertOk();

        $this->postJson('/api/curriculum/copy', ['from_grade_level_id' => $this->grade1->id, 'to_grade_level_ids' => [$this->grade7->id, $grade8->id]])->assertOk()->assertJsonPath('data.copied', 2);

        foreach ([$this->grade7, $grade8] as $grade) {
            $this->assertSame([5, 4], DB::table('grade_level_subjects')->where('grade_level_id', $grade->id)->orderBy('subject_id')->pluck('weekly_periods')->map(fn ($p) => (int) $p)->all());
        }
        $this->postJson('/api/curriculum/copy', ['from_grade_level_id' => $this->grade1->id, 'to_grade_level_ids' => [$this->grade1->id]])->assertUnprocessable();
    }

    public function test_the_standard_plan_gives_every_grade_from_1_to_10_its_full_week(): void
    {
        $this->actAsAdmin();
        $grades = collect(range(1, 10))->map(fn ($n) => GradeLevel::firstOrCreate(['code' => (string) $n], ['name_ar' => "صف $n", 'name_en' => "Grade $n", 'sort_order' => $n + 3]));
        $kg = GradeLevel::factory()->create(['code' => 'KG1', 'name_en' => 'KG1', 'sort_order' => 2]);

        $response = $this->postJson('/api/curriculum/standard-plan')->assertOk();

        $this->assertSame([], $response->json('data.missing_grades'));
        foreach ($grades as $grade) {
            $expected = in_array($grade->code, ['1', '2', '3'], true) ? 30 : 35; // grades 1-3 have a 6-period day
            $this->assertSame($expected, (int) DB::table('grade_level_subjects')->where('grade_level_id', $grade->id)->sum('weekly_periods'), "grade {$grade->code}");
        }
        $this->assertSame(0, DB::table('grade_level_subjects')->where('grade_level_id', $kg->id)->count(), 'kindergarten is left alone');
        $periods = fn (string $grade, string $code) => (int) DB::table('grade_level_subjects')
            ->where('grade_level_id', $grades->firstWhere('code', $grade)->id)->where('subject_id', Subject::where('code', $code)->value('id'))->value('weekly_periods');
        $this->assertSame(5, $periods('1', 'SCI'), 'one science subject in the lower grades');
        $this->assertSame(0, $periods('1', 'PHY'));
        $this->assertSame(2, $periods('9', 'PHY'));
        $this->assertSame(2, $periods('10', 'ESC'));
        $this->assertSame(0, $periods('9', 'SCI'));
        $this->assertSame(2, $periods('6', 'SOC'), 'social studies before grade 7');
        $this->assertSame(0, $periods('7', 'SOC'));
        $this->assertSame(1, $periods('7', 'HIS'));
        $this->assertSame(1, $periods('7', 'GEO'));
        $this->assertSame(1, $periods('7', 'NAT'));
        $this->assertSame(0, $periods('6', 'HIS'));
        foreach (['1', '5', '10'] as $g) {
            $this->assertGreaterThanOrEqual(5, $periods($g, 'ARB'), 'Arabic, English and maths every day');
            $this->assertSame(5, $periods($g, 'ENG'));
            $this->assertSame(5, $periods($g, 'MTH'));
        }
    }

    public function test_the_standard_plan_reuses_existing_subjects_by_code_reports_missing_grades_and_is_repeatable(): void
    {
        $this->actAsAdmin();
        $existing = Subject::create(['code' => 'ARB', 'name_ar' => 'عربي (اسم المدرسة)', 'name_en' => 'Arabic']);

        $first = $this->postJson('/api/curriculum/standard-plan')->assertOk();
        $count = DB::table('grade_level_subjects')->count();
        $this->postJson('/api/curriculum/standard-plan')->assertOk();

        $this->assertSame($count, DB::table('grade_level_subjects')->count());
        $this->assertSame(1, Subject::where('code', 'ARB')->count());
        $this->assertSame('عربي (اسم المدرسة)', $existing->fresh()->name_ar, 'the name the school chose is kept');
        $this->assertContains('2', $first->json('data.missing_grades'), 'grades the school does not have yet are reported');
        $this->assertNotContains('1', $first->json('data.missing_grades'));
        $this->assertSame(20, Subject::count(), 'the 20 standard subjects exist (grade 1 and 7 exist, so the plan is applied to them)');
    }

    public function test_an_assignment_needs_a_subject_of_the_curriculum_once_the_grade_has_one(): void
    {
        $room = Classroom::factory()->create(['grade_level_id' => $this->grade1->id]);
        $teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->science->id])->create();
        $this->actAsAdmin();
        $body = ['classroom_id' => $room->id, 'subject_id' => $this->science->id, 'teacher_id' => $teacher->id, 'weekly_periods' => 4];

        // No curriculum for the grade yet: anything goes.
        $this->postJson('/api/teaching-assignments', $body)->assertCreated();
        TeachingAssignment::query()->delete();

        $this->putJson('/api/curriculum', ['cells' => [$this->cell($this->grade1, $this->math, 5)]])->assertOk();
        $this->postJson('/api/teaching-assignments', $body)->assertUnprocessable()->assertJsonValidationErrors('subject_id');

        $this->putJson('/api/curriculum', ['cells' => [$this->cell($this->grade1, $this->math, 5), $this->cell($this->grade1, $this->science, 4)]])->assertOk();
        $this->postJson('/api/teaching-assignments', $body)->assertCreated();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/curriculum')->assertForbidden();
        $this->putJson('/api/curriculum', ['cells' => []])->assertForbidden();
        $this->postJson('/api/curriculum/standard-plan')->assertForbidden();
    }
}
