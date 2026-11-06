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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class QuizExtrasTest extends AcademicTestCase
{
    private User $teacher;

    private User $studentUser;

    private Subject $math;

    private int $lesson;

    /** @var list<Question> */
    private array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $grade = GradeLevel::factory()->create();
        $this->math = Subject::factory()->create();
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $room = Classroom::factory()->create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id]);
        TeachingAssignment::create(['classroom_id' => $room->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        $this->studentUser = User::factory()->role(Role::Student)->create();
        $student = Student::create(['student_number' => 'S-1', 'name_ar' => 'طالب', 'name_en' => 'Student', 'gender' => 'male', 'user_id' => $this->studentUser->id]);
        Enrollment::create(['student_id' => $student->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);
        $this->lesson = QuestionLesson::create(['grade_level_id' => $grade->id, 'subject_id' => $this->math->id, 'title' => 'L'])->id;
        foreach (['A', 'B', 'C'] as $n) {
            $q = Question::create(['question_lesson_id' => $this->lesson, 'created_by' => $this->teacher->id, 'body' => "Q $n", 'explanation' => "Why $n"]);
            $q->options()->createMany([['body' => 'right', 'is_correct' => true, 'position' => 0], ['body' => 'wrong', 'is_correct' => false, 'position' => 1]]);
            $this->questions[] = $q;
        }
        Sanctum::actingAs($this->studentUser);
    }

    private function start(array $extra = []): array
    {
        return $this->postJson('/api/quizzes', ['subject_id' => $this->math->id, 'lesson_ids' => [$this->lesson], 'count' => 3, ...$extra])->assertCreated()->json('data');
    }

    /** Answers every question of the quiz: the ones in $wrong get the wrong option. */
    private function answerAll(array $quiz, array $wrong = []): void
    {
        foreach ($quiz['questions'] as $q) {
            $text = in_array($q['body'], $wrong, true) ? 'wrong' : 'right';
            $this->postJson("/api/quizzes/{$quiz['id']}/answer", ['quiz_question_id' => $q['id'], 'option_id' => collect($q['options'])->firstWhere('body', $text)['id']])->assertOk();
        }
    }

    public function test_a_quiz_without_a_timer_has_no_deadline_and_a_timed_one_closes_when_time_runs_out(): void
    {
        $this->assertNull($this->start()['seconds_left']);

        Carbon::setTestNow('2026-11-10 10:00:00');
        $quiz = $this->start(['minutes' => 5]);
        $this->assertSame(300, $quiz['seconds_left']);
        $first = $quiz['questions'][0];
        $this->postJson("/api/quizzes/{$quiz['id']}/answer", ['quiz_question_id' => $first['id'], 'option_id' => $first['options'][0]['id']])->assertOk();

        Carbon::setTestNow('2026-11-10 10:06:00');
        $second = $quiz['questions'][1];
        $this->postJson("/api/quizzes/{$quiz['id']}/answer", ['quiz_question_id' => $second['id'], 'option_id' => $second['options'][0]['id']])->assertStatus(422);
        $this->getJson("/api/quizzes/{$quiz['id']}")->assertOk()->assertJsonPath('data.finished', true)->assertJsonPath('data.timed_out', true)->assertJsonPath('data.answered', 1);
        Carbon::setTestNow();
    }

    public function test_practice_on_mistakes_uses_only_what_the_student_still_gets_wrong_and_clears_what_they_fix(): void
    {
        $first = $this->start();
        $this->answerAll($first, ['Q A', 'Q B']); // C right, A and B wrong
        $this->getJson('/api/quizzes/options')->assertOk()->assertJsonPath('mistakes', 2)->assertJsonPath('data.0.mistakes', 2);

        $mistakes = $this->postJson('/api/quizzes', ['mode' => 'mistakes'])->assertCreated()->json('data');
        $this->assertSame('mistakes', $mistakes['kind']);
        $this->assertEqualsCanonicalizing(['Q A', 'Q B'], array_column($mistakes['questions'], 'body'));

        $this->answerAll($mistakes, ['Q B']); // A fixed, B still wrong
        $this->getJson('/api/quizzes/options')->assertJsonPath('mistakes', 1);
        $again = $this->postJson('/api/quizzes', ['mode' => 'mistakes'])->assertCreated()->json('data.questions');
        $this->assertSame(['Q B'], array_column($again, 'body'));

        $this->postJson('/api/quizzes', ['mode' => 'mistakes', 'count' => 1])->assertCreated();
        $this->answerAll($this->postJson('/api/quizzes', ['mode' => 'mistakes'])->json('data'));
        $this->postJson('/api/quizzes', ['mode' => 'mistakes'])->assertStatus(422); // nothing left to practise
    }

    public function test_a_finished_quiz_can_be_taken_again_with_the_same_questions_and_timer(): void
    {
        $quiz = $this->start(['minutes' => 10]);
        $this->postJson("/api/quizzes/{$quiz['id']}/retake")->assertStatus(422); // not finished yet
        $this->answerAll($quiz, ['Q A']);

        $new = $this->postJson("/api/quizzes/{$quiz['id']}/retake")->assertCreated()->json('data');
        $this->assertSame('retake', $new['kind']);
        $this->assertSame(10, $new['minutes']);
        $this->assertNotSame($quiz['id'], $new['id']);
        $this->assertEqualsCanonicalizing(array_column($quiz['questions'], 'body'), array_column($new['questions'], 'body'));
        $this->assertSame(0, $new['answered']);
    }

    public function test_pictures_are_private_and_the_explanation_picture_waits_for_a_wrong_answer(): void
    {
        Storage::fake();
        Sanctum::actingAs($this->teacher);
        $q = $this->questions[0];
        $this->postJson("/api/question-bank/questions/{$q->id}/image/question", ['image' => UploadedFile::fake()->image('q.png')])->assertOk()->assertJsonPath('data.has_image', true);
        $this->postJson("/api/question-bank/questions/{$q->id}/image/explanation", ['image' => UploadedFile::fake()->image('e.png')])->assertOk()->assertJsonPath('data.has_explanation_image', true);
        $this->postJson("/api/question-bank/questions/{$q->id}/image/question", ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertStatus(422);

        Sanctum::actingAs($this->studentUser);
        $quiz = $this->postJson('/api/quizzes', ['subject_id' => $this->math->id, 'lesson_ids' => [$this->lesson], 'count' => 3])->assertCreated()->json('data');
        $item = collect($quiz['questions'])->firstWhere('body', 'Q A');
        $this->assertTrue($item['has_image']);
        $this->get("/api/quizzes/{$quiz['id']}/items/{$item['id']}/image/question")->assertOk();
        $this->get("/api/quizzes/{$quiz['id']}/items/{$item['id']}/image/explanation")->assertForbidden(); // not before a wrong answer

        $wrong = $this->postJson("/api/quizzes/{$quiz['id']}/answer", ['quiz_question_id' => $item['id'], 'option_id' => collect($item['options'])->firstWhere('body', 'wrong')['id']])->assertOk();
        $this->assertTrue($wrong->json('has_explanation_image'));
        $this->get("/api/quizzes/{$quiz['id']}/items/{$item['id']}/image/explanation")->assertOk();

        $other = User::factory()->role(Role::Student)->create();
        Student::create(['student_number' => 'S-9', 'name_ar' => 'x', 'name_en' => 'x', 'gender' => 'male', 'user_id' => $other->id]);
        Sanctum::actingAs($other);
        $this->get("/api/quizzes/{$quiz['id']}/items/{$item['id']}/image/question")->assertForbidden();
    }
}
