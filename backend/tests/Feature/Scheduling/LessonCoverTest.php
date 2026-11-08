<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\PeriodSetup;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class LessonCoverTest extends AcademicTestCase
{
    private const MONDAY = '2026-11-09'; // day_of_week 1

    private AcademicYear $year;

    private Classroom $a;

    private Classroom $b;

    private Subject $math;

    private Subject $art;

    private User $sara;

    private User $omar;

    private User $lina;

    private User $busy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $grade = GradeLevel::factory()->create(['sort_order' => 7]);
        $this->a = Classroom::factory()->create(['name' => '7-A', 'grade_level_id' => $grade->id, 'academic_year_id' => $this->year->id]);
        $this->b = Classroom::factory()->create(['name' => '7-B', 'grade_level_id' => $grade->id, 'academic_year_id' => $this->year->id]);
        $this->math = Subject::factory()->create(['name_en' => 'Math']);
        $this->art = Subject::factory()->create(['name_en' => 'Art']);
        $this->sara = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create(['name' => 'Sara']);   // away
        $this->omar = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create(['name' => 'Omar']);   // free, teaches Math
        $this->lina = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->art->id])->create(['name' => 'Lina']);     // free, teaches Art
        $this->busy = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create(['name' => 'Busy']);    // has a lesson in period 1
        app(PeriodSetup::class)->apply(['days' => [0, 1, 2], 'periods_per_day' => 5, 'starts_at' => '08:00', 'period_minutes' => 45, 'gap_minutes' => 5]);
        $this->lesson($this->a, 1, 1, $this->sara);
        $this->lesson($this->b, 1, 1, $this->busy); // Busy teaches 7-B in period 1 on Monday
        $this->actAsAdmin();
    }

    private function lesson(Classroom $room, int $day, int $number, User $teacher): TimetableEntry
    {
        return TimetableEntry::create(['academic_year_id' => $this->year->id, 'classroom_id' => $room->id, 'period_id' => Period::where('day_of_week', $day)->where('number', $number)->value('id'), 'subject_id' => $this->math->id, 'teacher_id' => $teacher->id]);
    }

    private function plan(): array
    {
        return $this->getJson('/api/cover/plan?teacher_id='.$this->sara->id.'&date='.self::MONDAY)->assertOk()->json('data');
    }

    public function test_the_plan_lists_the_absent_teachers_lessons_with_the_free_teachers_best_first(): void
    {
        $plan = $this->plan();
        $this->assertCount(1, $plan);
        $this->assertSame('7-A', $plan[0]['classroom']['name']);
        $names = array_column(array_column($plan[0]['suggestions'], 'teacher'), 'name');
        $this->assertSame('Omar', $names[0]);             // teaches Math: first
        $this->assertContains('Lina', $names);
        $this->assertNotContains('Busy', $names);         // has a lesson in that period
        $this->assertNotContains('Sara', $names);         // the absent teacher herself
        $this->assertTrue($plan[0]['suggestions'][0]['teaches_subject']);
    }

    public function test_a_teacher_marked_absent_or_unavailable_is_not_suggested(): void
    {
        DB::table('staff_attendances')->insert(['user_id' => $this->omar->id, 'date' => self::MONDAY, 'status' => 'leave', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('teacher_unavailabilities')->insert(['teacher_id' => $this->lina->id, 'period_id' => Period::where('day_of_week', 1)->where('number', 1)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], $this->plan()[0]['suggestions']); // Busy teaches, Omar is on leave, Lina is unavailable, Sara is the absent one

        $away = collect($this->getJson('/api/cover/teachers?date='.self::MONDAY)->json('data'));
        $this->assertSame('leave', $away->firstWhere('name', 'Omar')['away']);
        $this->assertSame('Omar', $away->first()['name']); // those who are away come first
    }

    public function test_choosing_a_cover_saves_it_tells_the_teacher_and_takes_them_out_of_other_suggestions_for_that_period(): void
    {
        $period = Period::where('day_of_week', 1)->where('number', 1)->value('id');
        $this->postJson('/api/cover', ['date' => self::MONDAY, 'period_id' => $period, 'classroom_id' => $this->a->id, 'cover_teacher_id' => $this->omar->id])->assertCreated();

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->omar->id)->where('data', 'like', '%lesson_cover%')->count());
        $plan = $this->plan();
        $this->assertSame('Omar', $plan[0]['cover']['teacher']['name']);
        $this->assertSame([], $plan[0]['suggestions']); // covered already

        // Omar is covering in that period now, so he cannot cover another section at the same time (Busy is away that day).
        DB::table('staff_attendances')->insert(['user_id' => $this->busy->id, 'date' => self::MONDAY, 'status' => 'absent', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/cover', ['date' => self::MONDAY, 'period_id' => $period, 'classroom_id' => $this->b->id, 'cover_teacher_id' => $this->omar->id])->assertStatus(422);
        $this->postJson('/api/cover', ['date' => self::MONDAY, 'period_id' => $period, 'classroom_id' => $this->b->id, 'cover_teacher_id' => $this->lina->id])->assertCreated();

        $id = DB::table('lesson_covers')->value('id');
        $this->deleteJson("/api/cover/$id")->assertNoContent();
        $this->assertSame(1, DB::table('lesson_covers')->count()); // Lina's cover for 7-B stays
    }

    public function test_a_busy_teacher_a_wrong_day_and_a_missing_lesson_are_refused_and_only_the_admin_uses_it(): void
    {
        $p1 = Period::where('day_of_week', 1)->where('number', 1)->value('id');
        $this->postJson('/api/cover', ['date' => self::MONDAY, 'period_id' => $p1, 'classroom_id' => $this->a->id, 'cover_teacher_id' => $this->busy->id])->assertStatus(422)->assertJsonValidationErrors('cover_teacher_id');
        $this->postJson('/api/cover', ['date' => '2026-11-10', 'period_id' => $p1, 'classroom_id' => $this->a->id, 'cover_teacher_id' => $this->omar->id])->assertStatus(422)->assertJsonValidationErrors('period_id'); // a Tuesday
        $this->postJson('/api/cover', ['date' => self::MONDAY, 'period_id' => Period::where('day_of_week', 1)->where('number', 2)->value('id'), 'classroom_id' => $this->a->id, 'cover_teacher_id' => $this->omar->id])->assertStatus(422);

        Sanctum::actingAs($this->omar);
        $this->getJson('/api/cover/plan?teacher_id='.$this->sara->id.'&date='.self::MONDAY)->assertForbidden();
    }
}
