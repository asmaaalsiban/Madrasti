<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Services\AutoTeachingAssigner;
use App\Shared\Enums\StaffType;

/** Grade 1: the class teacher teaches every subject except the ones kept for a specialist (English, computer). */
class ClassTeacherTest extends AcademicTestCase
{
    private GradeLevel $grade1;

    private GradeLevel $grade5;

    private Classroom $room;

    private Subject $arabic;

    private Subject $math;

    private Subject $english;

    private Subject $computer;

    private User $classTeacher;

    private User $englishTeacher;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $this->grade1 = GradeLevel::factory()->create(['sort_order' => 1]);
        $this->grade5 = GradeLevel::factory()->create(['sort_order' => 5]);
        [$this->arabic, $this->math, $this->english, $this->computer] = array_map(fn ($n) => Subject::factory()->create(['name_en' => $n]), ['Arabic', 'Math', 'English', 'Computer']);
        foreach ([$this->arabic, $this->math, $this->english, $this->computer] as $s) {
            GradeLevelSubject::create(['grade_level_id' => $this->grade1->id, 'subject_id' => $s->id, 'weekly_periods' => 3]);
        }
        $this->classTeacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => null])->create(['name' => 'Hala']);
        $this->englishTeacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->english->id])->create(['name' => 'Eli']);
        $this->room = Classroom::factory()->create(['name' => '1-A', 'grade_level_id' => $this->grade1->id, 'academic_year_id' => $this->year->id, 'homeroom_teacher_id' => $this->classTeacher->id]);
        $this->actAsAdmin();
    }

    private function markGrade(array $specialists = []): void
    {
        $this->putJson('/api/curriculum/class-teachers', ['grade_level_id' => $this->grade1->id, 'class_teacher_grade' => true, 'specialist_subject_ids' => $specialists])->assertOk();
    }

    public function test_the_admin_marks_a_grade_and_the_subjects_kept_for_specialists(): void
    {
        $this->markGrade([$this->english->id, $this->computer->id]);
        $state = $this->getJson('/api/curriculum')->json('data');
        $this->assertTrue(collect($state['grades'])->firstWhere('id', $this->grade1->id)['class_teacher_grade']);
        $this->assertFalse(collect($state['grades'])->firstWhere('id', $this->grade5->id)['class_teacher_grade']);
        $specialist = collect($state['cells'])->where('specialist', true)->pluck('subject_id')->all();
        $this->assertEqualsCanonicalizing([$this->english->id, $this->computer->id], $specialist);
    }

    public function test_the_automatic_assigner_gives_the_class_teacher_all_but_the_specialist_subjects(): void
    {
        $this->markGrade([$this->english->id, $this->computer->id]);
        $result = app(AutoTeachingAssigner::class)->handle(24, false);

        $who = fn (Subject $s) => TeachingAssignment::where('classroom_id', $this->room->id)->where('subject_id', $s->id)->value('teacher_id');
        $this->assertSame($this->classTeacher->id, $who($this->arabic));
        $this->assertSame($this->classTeacher->id, $who($this->math));
        $this->assertSame($this->englishTeacher->id, $who($this->english)); // the specialist
        $this->assertNull($who($this->computer));                          // nobody teaches computer yet: reported as missing
        $this->assertSame(2, $result['class_teacher_assigned']);
    }

    public function test_a_class_teacher_subject_can_only_be_given_to_the_class_teacher(): void
    {
        $this->markGrade([$this->english->id]);
        $other = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $body = fn (User $t, Subject $s) => ['classroom_id' => $this->room->id, 'subject_id' => $s->id, 'teacher_id' => $t->id, 'weekly_periods' => 3];

        $this->postJson('/api/teaching-assignments', $body($other, $this->math))->assertStatus(422)->assertJsonValidationErrors('teacher_id'); // Math is the class teacher's
        $this->postJson('/api/teaching-assignments', $body($this->classTeacher, $this->math))->assertCreated();
        $this->postJson('/api/teaching-assignments', $body($this->classTeacher, $this->english))->assertStatus(422);  // English is the specialist's
        $this->postJson('/api/teaching-assignments', $body($this->englishTeacher, $this->english))->assertCreated();
    }

    public function test_a_section_without_a_class_teacher_is_reported_and_other_grades_are_not_affected(): void
    {
        $this->markGrade([$this->english->id]);
        $this->room->update(['homeroom_teacher_id' => null]);
        $result = app(AutoTeachingAssigner::class)->handle(24, true);
        $this->assertSame([['classroom' => '1-A', 'reason' => 'no_homeroom', 'periods' => 9]], $result['class_teacher_problems']); // Arabic, Math and Computer, 3 each (only English is kept for a specialist)
        $this->assertContains('no_class_teacher', array_column($this->getJson('/api/timetable/check')->json('data.issues'), 'type'));

        // Grade 5 is a normal grade: a subject teacher teaches there, the class-teacher rule does not apply.
        GradeLevelSubject::create(['grade_level_id' => $this->grade5->id, 'subject_id' => $this->math->id, 'weekly_periods' => 3]);
        $room5 = Classroom::factory()->create(['name' => '5-A', 'grade_level_id' => $this->grade5->id, 'academic_year_id' => $this->year->id]);
        $mathTeacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $this->postJson('/api/teaching-assignments', ['classroom_id' => $room5->id, 'subject_id' => $this->math->id, 'teacher_id' => $mathTeacher->id, 'weekly_periods' => 3])->assertCreated();
    }

    public function test_the_homeroom_teacher_is_the_class_teacher_without_ticking_anything_else(): void
    {
        $this->markGrade([$this->english->id, $this->computer->id]);
        $plain = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id, 'is_class_teacher' => false])->create(['name' => 'Plain']);
        $this->room->update(['homeroom_teacher_id' => $plain->id]);

        $result = app(AutoTeachingAssigner::class)->handle(24, false);
        $this->assertSame($plain->id, TeachingAssignment::where('classroom_id', $this->room->id)->where('subject_id', $this->arabic->id)->value('teacher_id'));
        $this->assertSame([], $result['class_teacher_problems']);
    }

    public function test_an_old_assignment_to_a_subject_teacher_is_replaced_when_the_grade_becomes_a_class_teacher_grade(): void
    {
        // Before: the school ran the automatic assignment as a normal grade, so a subject teacher got Arabic in grade 1.
        $arabicTeacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->arabic->id])->create(['name' => 'Subject teacher']);
        TeachingAssignment::create(['classroom_id' => $this->room->id, 'subject_id' => $this->arabic->id, 'teacher_id' => $arabicTeacher->id, 'weekly_periods' => 3]);

        $this->markGrade([$this->english->id, $this->computer->id]);
        $preview = app(AutoTeachingAssigner::class)->handle(24, true);
        $this->assertSame(1, $preview['invalid']); // the old Arabic assignment is reported as no longer right

        app(AutoTeachingAssigner::class)->handle(24, false);
        $this->assertSame($this->classTeacher->id, TeachingAssignment::where('classroom_id', $this->room->id)->where('subject_id', $this->arabic->id)->value('teacher_id'));
    }

    public function test_the_result_says_why_when_the_class_teacher_has_too_many_periods(): void
    {
        $this->markGrade([$this->english->id, $this->computer->id]);
        $result = app(AutoTeachingAssigner::class)->handle(4, true); // a limit of 4 a week: Arabic (3) fits, Math (3) does not
        $problem = $result['class_teacher_problems'][0];
        $this->assertSame('overload', $problem['reason']);
        $this->assertSame('Hala', $problem['teacher']);
        $this->assertSame(4, $problem['max']);
    }

    public function test_a_teacher_is_saved_as_a_class_teacher_from_the_staff_form(): void
    {
        $id = $this->postJson('/api/staff', ['name' => 'Rima', 'email' => 'rima@x.test', 'staff_type' => 'teacher', 'is_class_teacher' => true])->assertCreated()->assertJsonPath('data.is_class_teacher', true)->json('data.id');
        $this->putJson("/api/staff/$id", ['name' => 'Rima', 'email' => 'rima@x.test', 'staff_type' => 'teacher', 'is_class_teacher' => false])->assertOk()->assertJsonPath('data.is_class_teacher', false);
    }
}
