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
use App\Modules\QuestionBank\Models\Question;
use App\Modules\QuestionBank\Models\QuestionLesson;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class QuestionBankTest extends AcademicTestCase
{
    private User $teacher;

    private User $studentUser;

    private Student $student;

    private GradeLevel $grade;

    private GradeLevel $otherGrade;

    private Subject $math;

    private Subject $science;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->grade = GradeLevel::factory()->create();
        $this->otherGrade = GradeLevel::factory()->create();
        $this->math = Subject::factory()->create(['name_en' => 'Math']);
        $this->science = Subject::factory()->create(['name_en' => 'Science']);
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $room = Classroom::factory()->create(['academic_year_id' => $year->id, 'grade_level_id' => $this->grade->id]);
        TeachingAssignment::create(['classroom_id' => $room->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        $this->studentUser = User::factory()->role(Role::Student)->create();
        $this->student = Student::create(['student_number' => 'S-1', 'name_ar' => 'طالب', 'name_en' => 'Student', 'gender' => 'male', 'user_id' => $this->studentUser->id]);
        Enrollment::create(['student_id' => $this->student->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);
    }

    private function lesson(?GradeLevel $grade = null, ?Subject $subject = null, string $title = 'Fractions'): int
    {
        return QuestionLesson::create(['grade_level_id' => ($grade ?? $this->grade)->id, 'subject_id' => ($subject ?? $this->math)->id, 'title' => $title])->id;
    }

    /** @return array<string, mixed> */
    private function q(string $body = '1+1?'): array
    {
        return ['body' => $body, 'explanation' => 'Add them up.', 'options' => [['body' => '2', 'is_correct' => true], ['body' => '3', 'is_correct' => false], ['body' => '4', 'is_correct' => false]]];
    }

    public function test_a_teacher_writes_lessons_and_questions_only_for_what_they_teach(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/question-bank/scope')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject_en', 'Math');

        $id = $this->postJson('/api/question-bank/lessons', ['grade_level_id' => $this->grade->id, 'subject_id' => $this->math->id, 'title' => 'Fractions'])->assertCreated()->json('data.id');
        $this->postJson('/api/question-bank/lessons', ['grade_level_id' => $this->grade->id, 'subject_id' => $this->math->id, 'title' => 'Fractions'])->assertStatus(422);
        $this->postJson('/api/question-bank/lessons', ['grade_level_id' => $this->grade->id, 'subject_id' => $this->science->id, 'title' => 'Cells'])->assertForbidden();
        $this->postJson('/api/question-bank/lessons', ['grade_level_id' => $this->otherGrade->id, 'subject_id' => $this->math->id, 'title' => 'Fractions'])->assertForbidden();

        $this->postJson("/api/question-bank/lessons/$id/questions", $this->q())->assertCreated()->assertJsonCount(3, 'data.options');
        $this->getJson("/api/question-bank/lessons/$id/questions")->assertOk()->assertJsonPath('data.0.options.0.is_correct', true);
        $this->getJson("/api/question-bank/lessons?grade_level_id={$this->grade->id}&subject_id={$this->math->id}")->assertJsonPath('data.0.questions', 1);
    }

    public function test_a_question_needs_exactly_one_correct_option(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->lesson();
        $none = $this->q();
        $none['options'][0]['is_correct'] = false;
        $this->postJson("/api/question-bank/lessons/$id/questions", $none)->assertStatus(422)->assertJsonValidationErrors('options');
        $two = $this->q();
        $two['options'][1]['is_correct'] = true;
        $this->postJson("/api/question-bank/lessons/$id/questions", $two)->assertStatus(422);
    }

    public function test_a_student_builds_a_quiz_only_from_their_own_grade_and_never_sees_the_answers_early(): void
    {
        $mine = $this->lesson();
        $theirs = $this->lesson($this->otherGrade);
        foreach ([$mine, $theirs] as $l) {
            foreach (['A', 'B', 'C'] as $n) {
                Question::create(['question_lesson_id' => $l, 'body' => "Q $n", 'explanation' => 'Because.'])->options()->createMany([['body' => 'right', 'is_correct' => true, 'position' => 0], ['body' => 'wrong', 'is_correct' => false, 'position' => 1]]);
            }
        }
        Sanctum::actingAs($this->studentUser);
        $this->getJson('/api/quizzes/options')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lessons.0.questions', 3);

        $this->postJson('/api/quizzes', ['subject_id' => $this->math->id, 'lesson_ids' => [$theirs], 'count' => 3])->assertStatus(422); // another grade: not found for this student
        $res = $this->postJson('/api/quizzes', ['subject_id' => $this->math->id, 'lesson_ids' => [$mine, $theirs], 'count' => 10])->assertCreated()->assertJsonPath('reduced', true)->assertJsonPath('available', 3);
        $this->assertCount(3, $res->json('data.questions'));

        $raw = $res->getContent();
        $this->assertStringNotContainsString('is_correct":true', $raw);
        $this->assertStringNotContainsString('Because.', $raw);
        $this->assertArrayNotHasKey('explanation', $res->json('data.questions.0'));
    }

    public function test_a_right_answer_moves_on_and_a_wrong_one_shows_the_solution_and_the_quiz_ends_with_a_score(): void
    {
        $l = $this->lesson();
        foreach (['A', 'B'] as $n) {
            Question::create(['question_lesson_id' => $l, 'body' => "Q $n", 'explanation' => 'Because.'])->options()->createMany([['body' => 'right', 'is_correct' => true, 'position' => 0], ['body' => 'wrong', 'is_correct' => false, 'position' => 1]]);
        }
        Sanctum::actingAs($this->studentUser);
        $quiz = $this->postJson('/api/quizzes', ['subject_id' => $this->math->id, 'lesson_ids' => [$l], 'count' => 2])->assertCreated()->json('data');
        $pick = fn (array $question, string $text) => collect($question['options'])->firstWhere('body', $text)['id'];

        $right = $this->postJson("/api/quizzes/{$quiz['id']}/answer", ['quiz_question_id' => $quiz['questions'][0]['id'], 'option_id' => $pick($quiz['questions'][0], 'right')])->assertOk()->assertJsonPath('correct', true)->assertJsonPath('finished', false);
        $this->assertArrayNotHasKey('explanation', $right->json());

        $wrong = $this->postJson("/api/quizzes/{$quiz['id']}/answer", ['quiz_question_id' => $quiz['questions'][1]['id'], 'option_id' => $pick($quiz['questions'][1], 'wrong')])->assertOk()
            ->assertJsonPath('correct', false)->assertJsonPath('explanation', 'Because.')->assertJsonPath('finished', true)->assertJsonPath('score.correct', 1)->assertJsonPath('score.total', 2);
        $this->assertSame($pick($quiz['questions'][1], 'right'), $wrong->json('correct_option_id'));

        $this->postJson("/api/quizzes/{$quiz['id']}/answer", ['quiz_question_id' => $quiz['questions'][0]['id'], 'option_id' => $pick($quiz['questions'][0], 'right')])->assertStatus(422); // no second try
        $this->getJson('/api/quizzes')->assertOk()->assertJsonPath('data.0.correct', 1)->assertJsonPath('data.0.finished', true);
    }

    public function test_quizzes_are_private_to_their_student_but_parents_can_read_the_history(): void
    {
        $l = $this->lesson();
        Question::create(['question_lesson_id' => $l, 'body' => 'Q', 'explanation' => 'x'])->options()->createMany([['body' => 'a', 'is_correct' => true, 'position' => 0], ['body' => 'b', 'is_correct' => false, 'position' => 1]]);
        Sanctum::actingAs($this->studentUser);
        $id = $this->postJson('/api/quizzes', ['subject_id' => $this->math->id, 'lesson_ids' => [$l], 'count' => 1])->json('data.id');

        $parent = User::factory()->role(Role::Guardian)->create();
        $this->student->guardians()->attach($parent->id);
        Sanctum::actingAs($parent);
        $this->getJson("/api/students/{$this->student->id}/quizzes")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/quizzes/$id")->assertForbidden(); // a parent does not take the quiz

        $otherUser = User::factory()->role(Role::Student)->create();
        Student::create(['student_number' => 'S-2', 'name_ar' => 'آخر', 'name_en' => 'Other', 'gender' => 'male', 'user_id' => $otherUser->id]);
        Sanctum::actingAs($otherUser);
        $this->getJson("/api/quizzes/$id")->assertForbidden();
        $this->getJson("/api/students/{$this->student->id}/quizzes")->assertForbidden();
    }

    public function test_a_question_students_already_met_is_switched_off_not_deleted_and_students_cannot_write(): void
    {
        $l = $this->lesson();
        $q = Question::create(['question_lesson_id' => $l, 'body' => 'Q', 'explanation' => 'x']);
        $q->options()->createMany([['body' => 'a', 'is_correct' => true, 'position' => 0], ['body' => 'b', 'is_correct' => false, 'position' => 1]]);
        Sanctum::actingAs($this->studentUser);
        $this->postJson('/api/quizzes', ['subject_id' => $this->math->id, 'lesson_ids' => [$l], 'count' => 1])->assertCreated();
        $this->postJson('/api/question-bank/lessons', ['grade_level_id' => $this->grade->id, 'subject_id' => $this->math->id, 'title' => 'X'])->assertForbidden();

        Sanctum::actingAs($this->teacher);
        $this->deleteJson("/api/question-bank/questions/{$q->id}")->assertNoContent();
        $this->assertFalse($q->refresh()->is_active);
        $this->deleteJson("/api/question-bank/lessons/$l")->assertStatus(422); // still has a question
    }
}
