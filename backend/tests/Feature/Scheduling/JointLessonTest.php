<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\PeriodSetup;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Academic\AcademicTestCase;

/** One teacher teaches the same subject to two sections at once (a joint lesson). */
class JointLessonTest extends AcademicTestCase
{
    private Classroom $a;

    private Classroom $b;

    private Classroom $c;

    private Subject $sport;

    private Subject $math;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $grade = GradeLevel::factory()->create(['sort_order' => 7]);
        [$this->a, $this->b, $this->c] = array_map(fn ($n) => Classroom::factory()->create(['name' => $n, 'grade_level_id' => $grade->id, 'academic_year_id' => $year->id]), ['7-A', '7-B', '7-C']);
        $this->sport = Subject::factory()->create(['name_en' => 'Sport']);
        $this->math = Subject::factory()->create(['name_en' => 'Math']);
        $this->coach = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->sport->id])->create(['name' => 'Coach']);
        foreach ([$this->a, $this->b] as $room) {
            TeachingAssignment::create(['classroom_id' => $room->id, 'subject_id' => $this->sport->id, 'teacher_id' => $this->coach->id, 'weekly_periods' => 2]);
        }
        TeachingAssignment::create(['classroom_id' => $this->c->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->coach->id, 'weekly_periods' => 2]);
        app(PeriodSetup::class)->apply(['days' => [0, 1], 'periods_per_day' => 5, 'starts_at' => '08:00', 'period_minutes' => 45, 'gap_minutes' => 5]);
        $this->actAsAdmin();
    }

    private function placeLesson(Classroom $room, Subject $subject, array $extra = [])
    {
        return $this->putJson('/api/timetable/entries', ['classroom_id' => $room->id, 'period_id' => Period::where('day_of_week', 0)->where('number', 1)->value('id'), 'subject_id' => $subject->id, ...$extra]);
    }

    public function test_without_the_joint_option_a_teacher_still_cannot_be_in_two_sections_at_once(): void
    {
        $this->placeLesson($this->a, $this->sport)->assertOk()->assertJsonPath('data.is_joint', false);
        $this->placeLesson($this->b, $this->sport)->assertStatus(422)->assertJsonValidationErrors('period_id');
    }

    public function test_a_joint_lesson_puts_the_same_teacher_and_subject_in_two_sections_and_marks_both(): void
    {
        $this->placeLesson($this->a, $this->sport)->assertOk();
        $this->placeLesson($this->b, $this->sport, ['joint' => true])->assertOk()->assertJsonPath('data.is_joint', true);

        $this->assertSame(2, TimetableEntry::where('teacher_id', $this->coach->id)->where('is_joint', true)->count());
        $teacher = $this->getJson("/api/timetable?teacher_id={$this->coach->id}")->json('data.entries');
        $this->assertCount(2, $teacher);
        $this->assertSame([true, true], array_column($teacher, 'is_joint'));

        // The checker does not call it a clash.
        $this->assertNotContains('teacher_double_booked', array_column($this->getJson('/api/timetable/check')->json('data.issues'), 'type'));
    }

    public function test_a_joint_lesson_must_be_the_same_subject(): void
    {
        $this->placeLesson($this->a, $this->sport)->assertOk();
        $this->placeLesson($this->c, $this->math, ['joint' => true])->assertStatus(422)->assertJsonValidationErrors('period_id'); // Math with Sport at once: no
    }

    public function test_removing_one_side_makes_the_other_a_normal_lesson_again(): void
    {
        $this->placeLesson($this->a, $this->sport)->assertOk();
        $id = $this->placeLesson($this->b, $this->sport, ['joint' => true])->json('data.id');
        $this->deleteJson("/api/timetable/entries/$id")->assertNoContent();
        $this->assertFalse(TimetableEntry::where('classroom_id', $this->a->id)->first()->is_joint);

        // And replacing a joint cell with another subject does the same.
        $this->placeLesson($this->b, $this->sport, ['joint' => true])->assertOk();
        $this->placeLesson($this->b, $this->sport, ['joint' => false])->assertStatus(422); // Coach is busy in 7-A: not joint any more
    }

    public function test_two_lessons_that_are_not_marked_joint_are_still_reported_as_a_clash(): void
    {
        $period = Period::where('day_of_week', 1)->where('number', 1)->value('id');
        foreach ([$this->a, $this->b] as $room) {
            TimetableEntry::create(['academic_year_id' => AcademicYear::where('is_current', true)->value('id'), 'classroom_id' => $room->id, 'period_id' => $period, 'subject_id' => $this->sport->id, 'teacher_id' => $this->coach->id]);
        }
        $this->assertContains('teacher_double_booked', array_column($this->getJson('/api/timetable/check')->json('data.issues'), 'type'));
    }

    public function test_regenerating_the_timetable_keeps_a_joint_lesson(): void
    {
        $this->placeLesson($this->a, $this->sport)->assertOk();
        $this->placeLesson($this->b, $this->sport, ['joint' => true])->assertOk();

        $this->postJson('/api/timetable/generate', ['seed' => 1])->assertOk();

        $kept = TimetableEntry::where('teacher_id', $this->coach->id)->where('is_joint', true)->get();
        $this->assertCount(2, $kept);
        $this->assertSame(1, $kept->pluck('period_id')->unique()->count()); // still the same period, in both sections
    }

    public function test_a_joint_lesson_counts_once_in_the_teachers_weekly_load(): void
    {
        $this->placeLesson($this->a, $this->sport)->assertOk();
        $this->placeLesson($this->b, $this->sport, ['joint' => true])->assertOk();
        // Sport is assigned 2 + 2 periods (one assignment per section) and Math 2: 6 on paper, one of the periods is taught to both sections.
        $coach = collect($this->getJson('/api/staff')->json('data'))->firstWhere('id', $this->coach->id);
        $this->assertSame(5, $coach['weekly_load']);

        DB::table('school_settings')->insert(['key' => 'max_teacher_load', 'value' => json_encode(5), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNotContains('teacher_overloaded', array_column($this->getJson('/api/timetable/check')->json('data.issues'), 'type')); // 5 is not over 5
    }
}
