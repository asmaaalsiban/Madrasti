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

/** A teacher can teach more than one subject: the main one for their grades, and extra ones each for its own grades. */
class ExtraSubjectsTest extends AcademicTestCase
{
    private Subject $math;

    private Subject $science;

    private GradeLevel $g5;

    private GradeLevel $g6;

    private GradeLevel $g8;

    private GradeLevel $g9;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $this->math = Subject::factory()->create(['name_en' => 'Math']);
        $this->science = Subject::factory()->create(['name_en' => 'Science']);
        [$this->g5, $this->g6, $this->g8, $this->g9] = array_map(fn ($o) => GradeLevel::factory()->create(['sort_order' => $o]), [5, 6, 8, 9]);
        foreach ([$this->g5, $this->g6, $this->g8, $this->g9] as $g) {
            foreach ([$this->math, $this->science] as $s) {
                GradeLevelSubject::create(['grade_level_id' => $g->id, 'subject_id' => $s->id, 'weekly_periods' => 3]);
            }
        }
        $this->actAsAdmin();
    }

    private function room(GradeLevel $g, string $name): Classroom
    {
        return Classroom::factory()->create(['name' => $name, 'grade_level_id' => $g->id, 'academic_year_id' => $this->year->id]);
    }

    /** @return array<string, mixed> */
    private function teacherBody(array $extra = []): array
    {
        return ['name' => 'Omar', 'email' => 'omar@x.test', 'staff_type' => 'teacher', 'subject_id' => $this->math->id, 'grade_from_id' => $this->g5->id, 'grade_to_id' => $this->g6->id,
            'extra_subjects' => [['subject_id' => $this->science->id, 'grade_from_id' => $this->g8->id, 'grade_to_id' => $this->g9->id]], ...$extra];
    }

    private function assign(User|int $teacher, Subject $subject, Classroom $room)
    {
        return $this->postJson('/api/teaching-assignments', ['classroom_id' => $room->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher instanceof User ? $teacher->id : $teacher, 'weekly_periods' => 3]);
    }

    public function test_the_staff_form_saves_extra_subjects_with_their_own_grades(): void
    {
        $id = $this->postJson('/api/staff', $this->teacherBody())->assertCreated()
            ->assertJsonPath('data.extra_subjects.0.subject_id', $this->science->id)->assertJsonPath('data.extra_subjects.0.grade_from_id', $this->g8->id)->json('data.id');
        $this->putJson("/api/staff/$id", $this->teacherBody(['extra_subjects' => []]))->assertOk()->assertJsonPath('data.extra_subjects', []);
    }

    public function test_extra_subjects_are_validated(): void
    {
        $this->postJson('/api/staff', $this->teacherBody(['extra_subjects' => [['subject_id' => $this->math->id]]]))->assertStatus(422)->assertJsonValidationErrors('extra_subjects.0.subject_id'); // same as the main one
        $this->postJson('/api/staff', $this->teacherBody(['extra_subjects' => [['subject_id' => $this->science->id, 'grade_from_id' => $this->g9->id, 'grade_to_id' => $this->g8->id]]]))->assertStatus(422)->assertJsonValidationErrors('extra_subjects.0.grade_to_id');
        $this->postJson('/api/staff', $this->teacherBody(['extra_subjects' => [['subject_id' => $this->science->id], ['subject_id' => $this->science->id]]]))->assertStatus(422);
    }

    public function test_a_teacher_can_be_assigned_each_subject_only_for_its_own_grades(): void
    {
        $id = $this->postJson('/api/staff', $this->teacherBody())->json('data.id');
        $this->assign($id, $this->math, $this->room($this->g5, '5A'))->assertCreated();
        $this->assign($id, $this->science, $this->room($this->g8, '8A'))->assertCreated();   // the extra subject, in its grades
        $this->assign($id, $this->math, $this->room($this->g8, '8B'))->assertStatus(422);     // the main subject is not for grade 8
        $this->assign($id, $this->science, $this->room($this->g5, '5B'))->assertStatus(422);  // the extra subject is not for grade 5
    }

    public function test_an_extra_subject_without_grades_follows_the_teachers_grades(): void
    {
        $id = $this->postJson('/api/staff', $this->teacherBody(['extra_subjects' => [['subject_id' => $this->science->id]]]))->json('data.id');
        $this->assign($id, $this->science, $this->room($this->g6, '6A'))->assertCreated();
        $this->assign($id, $this->science, $this->room($this->g9, '9A'))->assertStatus(422);
    }

    public function test_the_teacher_list_can_be_filtered_by_either_subject(): void
    {
        $id = $this->postJson('/api/staff', $this->teacherBody())->json('data.id');
        $this->getJson("/api/staff?subject_id={$this->math->id}")->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/staff?subject_id={$this->science->id}")->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_the_automatic_assigner_uses_the_extra_subject_in_its_grades(): void
    {
        $this->postJson('/api/staff', $this->teacherBody())->assertCreated();
        $r5 = $this->room($this->g5, '5A');
        $r8 = $this->room($this->g8, '8A');
        app(AutoTeachingAssigner::class)->handle(24, false);

        $this->assertSame(['Math', 'Science'], collect([[$r5, $this->math], [$r8, $this->science]])->map(fn ($p) => TeachingAssignment::where('classroom_id', $p[0]->id)->where('subject_id', $p[1]->id)->exists() ? $p[1]->name_en : null)->all());
        $this->assertFalse(TeachingAssignment::where('classroom_id', $r8->id)->where('subject_id', $this->math->id)->exists()); // grade 8 Math is not his
    }
}
