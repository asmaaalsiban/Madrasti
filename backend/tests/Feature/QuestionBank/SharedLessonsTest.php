<?php

namespace Tests\Feature\QuestionBank;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

/** Grade 9 has two sections with two English teachers: one lesson, one bank, each teacher keeps their own questions. */
class SharedLessonsTest extends AcademicTestCase
{
    private User $teacherA;

    private User $teacherB;

    private User $studentB;

    private GradeLevel $grade;

    private Subject $english;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->grade = GradeLevel::factory()->create();
        $this->english = Subject::factory()->create(['name_en' => 'English']);
        $a = Classroom::factory()->create(['name' => '9A', 'academic_year_id' => $year->id, 'grade_level_id' => $this->grade->id]);
        $b = Classroom::factory()->create(['name' => '9B', 'academic_year_id' => $year->id, 'grade_level_id' => $this->grade->id]);
        $this->teacherA = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->english->id])->create(['name' => 'Mr A']);
        $this->teacherB = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->english->id])->create(['name' => 'Ms B']);
        TeachingAssignment::create(['classroom_id' => $a->id, 'subject_id' => $this->english->id, 'teacher_id' => $this->teacherA->id, 'weekly_periods' => 4]);
        TeachingAssignment::create(['classroom_id' => $b->id, 'subject_id' => $this->english->id, 'teacher_id' => $this->teacherB->id, 'weekly_periods' => 4]);
        $this->studentB = User::factory()->role(Role::Student)->create();
        $s = Student::create(['student_number' => 'S-1', 'name_ar' => 'ب', 'name_en' => 'B', 'gender' => 'male', 'user_id' => $this->studentB->id]);
        Enrollment::create(['student_id' => $s->id, 'classroom_id' => $b->id, 'academic_year_id' => $year->id]);
    }

    private function q(string $body): array
    {
        return ['body' => $body, 'options' => [['body' => 'a', 'is_correct' => true], ['body' => 'b', 'is_correct' => false]]];
    }

    public function test_two_teachers_of_the_same_grade_fill_one_lesson_and_students_of_both_sections_get_all_the_questions(): void
    {
        Sanctum::actingAs($this->teacherA);
        $lesson = $this->postJson('/api/question-bank/lessons', ['grade_level_id' => $this->grade->id, 'subject_id' => $this->english->id, 'title' => 'Present continuous'])->assertCreated()->json('data.id');
        $this->postJson("/api/question-bank/lessons/$lesson/questions", $this->q('Q from A'))->assertCreated();

        Sanctum::actingAs($this->teacherB);
        $list = $this->getJson("/api/question-bank/lessons?grade_level_id={$this->grade->id}&subject_id={$this->english->id}")->assertOk()->json('data');
        $this->assertCount(1, $list); // B sees A's lesson and picks it
        $this->assertSame(['Mr A'], $list[0]['teachers']);
        $this->postJson('/api/question-bank/lessons', ['grade_level_id' => $this->grade->id, 'subject_id' => $this->english->id, 'title' => 'Present Continuous'])->assertOk()->assertJsonPath('data.id', $lesson);
        $this->postJson("/api/question-bank/lessons/$lesson/questions", $this->q('Q from B'))->assertCreated();

        $questions = $this->getJson("/api/question-bank/lessons/$lesson/questions")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Mr A', 'Ms B'], array_column($questions, 'author'));
        $this->assertEqualsCanonicalizing([false, true], array_column($questions, 'mine'));

        Sanctum::actingAs($this->studentB); // a student of 9B quizzes on the questions of both teachers
        $quiz = $this->postJson('/api/quizzes', ['subject_id' => $this->english->id, 'lesson_ids' => [$lesson], 'count' => 10])->assertCreated()->json('data');
        $this->assertEqualsCanonicalizing(['Q from A', 'Q from B'], array_column($quiz['questions'], 'body'));
    }

    public function test_a_teacher_cannot_change_or_remove_another_teachers_question_or_lesson(): void
    {
        Sanctum::actingAs($this->teacherA);
        $lesson = $this->postJson('/api/question-bank/lessons', ['grade_level_id' => $this->grade->id, 'subject_id' => $this->english->id, 'title' => 'Tenses'])->json('data.id');
        $q = $this->postJson("/api/question-bank/lessons/$lesson/questions", $this->q('A only'))->json('data.id');

        Sanctum::actingAs($this->teacherB);
        $this->putJson("/api/question-bank/questions/$q", $this->q('hacked'))->assertForbidden();
        $this->deleteJson("/api/question-bank/questions/$q")->assertForbidden();
        $this->putJson("/api/question-bank/lessons/$lesson", ['title' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/question-bank/lessons/$lesson")->assertForbidden();

        Sanctum::actingAs($this->teacherA);
        $this->putJson("/api/question-bank/questions/$q", $this->q('A edited'))->assertOk();
    }
}
