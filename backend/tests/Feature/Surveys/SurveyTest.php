<?php

namespace Tests\Feature\Surveys;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyRecipient;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class SurveyTest extends AcademicTestCase
{
    private User $teacher;

    private Classroom $a;

    private Classroom $b;

    private GradeLevel $g4;

    private GradeLevel $g5;

    private GradeLevel $g6;

    private User $dad;      // two children in 5-A and 6-B

    private User $mum;      // one child in 5-A

    private User $other;    // one child in 5-B

    /** @var array<string, Student> */
    private array $kids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $math = Subject::factory()->create();
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create();
        $this->g4 = GradeLevel::factory()->create(['name_en' => 'Grade 4', 'sort_order' => 4]);
        $this->g5 = GradeLevel::factory()->create(['name_en' => 'Grade 5', 'sort_order' => 5]);
        $this->g6 = GradeLevel::factory()->create(['name_en' => 'Grade 6', 'sort_order' => 6]);
        $this->a = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id, 'grade_level_id' => $this->g5->id]);
        $this->b = Classroom::factory()->create(['name' => '6-B', 'academic_year_id' => $year->id, 'grade_level_id' => $this->g6->id]);
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        $this->dad = User::factory()->role(Role::Guardian)->create(['name' => 'Abu Ali']);
        $this->mum = User::factory()->role(Role::Guardian)->create(['name' => 'Um Sami']);
        $this->other = User::factory()->role(Role::Guardian)->create(['name' => 'Abu Khaled']);
        foreach ([['ali', $this->a, $this->dad], ['dana', $this->b, $this->dad], ['sami', $this->a, $this->mum], ['khaled', $this->b, $this->other]] as [$key, $room, $parent]) {
            $s = Student::create(['student_number' => "S-$key", 'name_ar' => $key, 'name_en' => $key, 'gender' => 'male']);
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);
            $s->guardians()->attach($parent->id, ['is_primary' => true]);
            $this->kids[$key] = $s;
        }
    }

    private function pollBody(array $extra = []): array
    {
        return ['type' => 'poll', 'title' => 'Trip day?', 'audience' => 'all', 'questions' => [['type' => 'single_choice', 'text' => 'Which day?', 'options' => ['Sunday', 'Monday']]], ...$extra];
    }

    private function approvalBody(array $extra = []): array
    {
        return ['type' => 'approval', 'title' => 'Zoo trip', 'description' => 'Friday, 8am', 'audience' => 'classrooms', 'classroom_ids' => [$this->a->id], 'questions' => [['type' => 'text', 'text' => 'Emergency phone', 'is_required' => true]], ...$extra];
    }

    private function mine()
    {
        return collect($this->getJson('/api/my-surveys')->assertOk()->json('data'));
    }

    public function test_a_poll_for_all_parents_has_one_recipient_per_parent_and_tells_them(): void
    {
        $this->actAsAdmin();
        $survey = $this->postJson('/api/surveys', $this->pollBody())->assertCreated()->assertJsonPath('data.recipient_count', 3)->assertJsonPath('data.status', 'open');

        $this->assertSame(3, SurveyRecipient::where('survey_id', $survey->json('data.id'))->count()); // dad has two children but answers once
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->dad->id)->where('data', 'like', '%survey_new%')->count());
    }

    public function test_an_approval_is_asked_for_each_child_of_the_section(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/surveys', $this->approvalBody())->assertCreated()->assertJsonPath('data.recipient_count', 2); // Ali (dad) and Sami (mum): 5-A only

        Sanctum::actingAs($this->dad);
        $rows = $this->mine();
        $this->assertCount(1, $rows);
        $this->assertSame('Ali', ucfirst($rows[0]['student']['name_en']));

        // A parent with two children in the section gets one approval for each.
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->actAsAdmin();
        $this->postJson('/api/surveys', $this->approvalBody(['title' => 'All parents trip', 'audience' => 'all', 'classroom_ids' => null]))->assertCreated()->assertJsonPath('data.recipient_count', 4);
    }

    public function test_the_questions_are_checked(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/surveys', $this->pollBody(['questions' => []]))->assertUnprocessable()->assertJsonValidationErrors('questions');
        $this->postJson('/api/surveys', $this->pollBody(['questions' => [['type' => 'text', 'text' => 'Why?']]]))->assertUnprocessable();
        $this->postJson('/api/surveys', $this->pollBody(['questions' => [['type' => 'single_choice', 'text' => 'One choice', 'options' => ['Only']]]]))->assertUnprocessable();
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'classrooms']))->assertUnprocessable()->assertJsonValidationErrors('classroom_ids');
        $this->postJson('/api/surveys', $this->pollBody(['closes_at' => now()->subDay()->toDateTimeString()]))->assertUnprocessable();
        $this->postJson('/api/surveys', ['type' => 'approval', 'title' => 'Plain permission', 'audience' => 'all'])->assertCreated(); // an approval needs no questions
    }

    public function test_a_teacher_writes_only_to_their_sections_and_sees_only_their_surveys(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/surveys', $this->pollBody())->assertForbidden(); // all parents: admin only
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'classrooms', 'classroom_ids' => [$this->b->id]]))->assertForbidden(); // not their section
        $mine = $this->postJson('/api/surveys', $this->approvalBody())->assertCreated()->json('data.id');

        $this->actAsAdmin();
        $theirs = $this->postJson('/api/surveys', $this->pollBody())->json('data.id');
        $this->assertCount(2, $this->getJson('/api/surveys')->json('data')); // the admin sees all

        Sanctum::actingAs($this->teacher);
        $this->assertSame([$mine], array_column($this->getJson('/api/surveys')->json('data'), 'id'));
        $this->getJson("/api/surveys/$theirs")->assertForbidden();
        $this->postJson("/api/surveys/$theirs/close")->assertForbidden();
    }

    public function test_a_parent_answers_and_can_change_the_answer_until_it_closes(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/surveys', $this->pollBody());
        Sanctum::actingAs($this->dad);
        $row = $this->mine()[0];
        $this->assertSame('pending', $row['state']);
        $qid = $row['questions'][0]['id'];

        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => []])->assertUnprocessable(); // required
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => [$qid => 'Friday']])->assertUnprocessable(); // not a choice
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => [$qid => 'Sunday']])->assertOk()->assertJsonPath('data.state', 'answered');
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => [$qid => 'Monday']])->assertOk();

        $again = $this->mine()[0];
        $this->assertSame('answered', $again['state']);
        $this->assertSame('Monday', $again['answers'][$qid]);
        $this->assertSame(1, DB::table('survey_responses')->count()); // replaced, not added
        $this->assertSame(0, $this->getJson('/api/my-surveys/pending')->json('data.count') - 0);

        Survey::query()->update(['closed_at' => now()]);
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => [$qid => 'Sunday']])->assertUnprocessable(); // closed
    }

    public function test_an_approval_needs_a_decision_and_a_decline_skips_the_required_answers(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/surveys', $this->approvalBody());
        Sanctum::actingAs($this->dad);
        $row = $this->mine()[0];

        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => []])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['decision' => 'approved', 'answers' => []])->assertUnprocessable(); // approving needs the phone
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['decision' => 'declined'])->assertOk()->assertJsonPath('data.state', 'declined');
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['decision' => 'approved', 'answers' => [$row['questions'][0]['id'] => '0790000001']])->assertOk()->assertJsonPath('data.state', 'approved');
        $this->assertSame('approved', $this->mine()[0]['state']);
    }

    public function test_the_creator_sees_who_answered_who_did_not_and_the_counts(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/surveys', $this->pollBody())->json('data.id');
        foreach ([[$this->dad, 'Sunday'], [$this->mum, 'Sunday']] as [$parent, $choice]) {
            Sanctum::actingAs($parent);
            $row = $this->mine()[0];
            $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => [$row['questions'][0]['id'] => $choice]])->assertOk();
        }

        $this->actAsAdmin();
        $data = $this->getJson("/api/surveys/$id")->assertOk()->json('data');
        $this->assertSame([3, 2], [$data['recipient_count'], $data['response_count']]);
        $this->assertSame(['Sunday' => 2, 'Monday' => 0], collect($data['questions'][0]['counts'])->pluck('count', 'option')->all());
        $this->assertSame(['pending'], collect($data['recipients'])->pluck('state')->unique()->reject(fn ($s) => $s === 'answered')->values()->all());
        $this->assertSame('Abu Khaled', $data['recipients'][0]['parent']); // the ones still waiting come first
    }

    public function test_an_approval_result_counts_approved_and_declined_children(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/surveys', $this->approvalBody(['questions' => []]))->json('data.id');
        foreach ([[$this->dad, 'approved'], [$this->mum, 'declined']] as [$parent, $decision]) {
            Sanctum::actingAs($parent);
            $this->postJson('/api/my-surveys/'.$this->mine()[0]['id'].'/respond', ['decision' => $decision])->assertOk();
        }

        $this->actAsAdmin();
        $data = $this->getJson("/api/surveys/$id")->json('data');
        $this->assertSame([1, 1], [$data['approved'], $data['declined']]);
        $this->assertSame(['approved', 'declined'], collect($data['recipients'])->pluck('state')->sort()->values()->all());
    }

    public function test_text_answers_are_listed_for_the_creator(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/surveys', ['type' => 'survey', 'title' => 'Feedback', 'audience' => 'all', 'questions' => [['type' => 'text', 'text' => 'Ideas?'], ['type' => 'multiple_choice', 'text' => 'Topics', 'options' => ['Math', 'Art', 'Sport'], 'is_required' => false], ['type' => 'yes_no', 'text' => 'Happy?']]])->json('data.id');
        Sanctum::actingAs($this->dad);
        $row = $this->mine()[0];
        [$text, $multi, $yn] = array_column($row['questions'], 'id');
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => [$text => 'More sport', $multi => ['Math', 'Sport'], $yn => 'yes']])->assertOk();
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => [$text => 'x', $multi => ['Chess'], $yn => 'yes']])->assertUnprocessable();

        $this->actAsAdmin();
        $q = $this->getJson("/api/surveys/$id")->json('data.questions');
        $this->assertSame(['More sport'], $q[0]['texts']);
        $this->assertSame(['Math' => 1, 'Art' => 0, 'Sport' => 1], collect($q[1]['counts'])->pluck('count', 'option')->all());
        $this->assertSame(['yes' => 1, 'no' => 0], collect($q[2]['counts'])->pluck('count', 'option')->all());
    }

    public function test_a_reminder_goes_to_those_who_have_not_answered_at_most_every_six_hours(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/surveys', $this->pollBody())->json('data.id');
        Sanctum::actingAs($this->dad);
        $row = $this->mine()[0];
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => [$row['questions'][0]['id'] => 'Sunday']]);

        $this->actAsAdmin();
        $this->postJson("/api/surveys/$id/remind")->assertOk()->assertJsonPath('data.reminded', 2); // mum and the other, not dad
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->mum->id)->where('data', 'like', '%survey_reminder%')->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->dad->id)->where('data', 'like', '%survey_reminder%')->count());
        $this->postJson("/api/surveys/$id/remind")->assertUnprocessable();
    }

    public function test_closing_and_deleting(): void
    {
        $this->actAsAdmin();
        $empty = $this->postJson('/api/surveys', $this->pollBody())->json('data.id');
        $used = $this->postJson('/api/surveys', $this->pollBody(['title' => 'Used']))->json('data.id');
        Sanctum::actingAs($this->dad);
        $row = $this->mine()->firstWhere('title', 'Used');
        $this->postJson("/api/my-surveys/{$row['id']}/respond", ['answers' => [$row['questions'][0]['id'] => 'Sunday']]);

        $this->actAsAdmin();
        $this->deleteJson("/api/surveys/$used")->assertUnprocessable(); // has an answer: close it instead
        $this->postJson("/api/surveys/$used/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("/api/surveys/$used/remind")->assertUnprocessable();
        $this->deleteJson("/api/surveys/$empty")->assertNoContent();
        $this->assertNull(Survey::find($empty));
    }

    public function test_a_parent_cannot_answer_for_someone_else_and_only_parents_have_the_list(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/surveys', $this->pollBody());
        $recipient = SurveyRecipient::where('user_id', $this->dad->id)->first();

        Sanctum::actingAs($this->mum);
        $this->postJson("/api/my-surveys/{$recipient->id}/respond", ['answers' => []])->assertNotFound();
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/my-surveys')->assertForbidden();
        Sanctum::actingAs($this->dad);
        $this->getJson('/api/surveys')->assertForbidden();
        $this->postJson('/api/surveys', $this->pollBody())->assertForbidden();
    }

    public function test_a_survey_can_go_to_a_range_of_grades_in_either_order(): void
    {
        $this->actAsAdmin();
        // Grade 5 only: Ali (dad) and Sami (mum), not the Grade 6 children.
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'grades', 'grade_from_id' => $this->g5->id, 'grade_to_id' => $this->g5->id]))->assertCreated()->assertJsonPath('data.recipient_count', 2);
        // Grade 4 to 6 covers everyone; given backwards it is the same.
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'grades', 'grade_from_id' => $this->g4->id, 'grade_to_id' => $this->g6->id]))->assertCreated()->assertJsonPath('data.recipient_count', 3);
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'grades', 'grade_from_id' => $this->g6->id, 'grade_to_id' => $this->g4->id]))->assertCreated()->assertJsonPath('data.recipient_count', 3);
        // Grade 6 only: Dana (dad) and Khaled (the other parent).
        $six = $this->postJson('/api/surveys', $this->approvalBody(['audience' => 'grades', 'grade_from_id' => $this->g6->id, 'grade_to_id' => $this->g6->id, 'classroom_ids' => null]))->assertCreated();
        $this->assertSame(2, $six->json('data.recipient_count'));
        $this->assertSame('Grade 6', $six->json('data.audience_detail.to.name_en'));
    }

    public function test_a_survey_can_go_to_several_chosen_sections(): void
    {
        $this->actAsAdmin();
        $r = $this->postJson('/api/surveys', $this->pollBody(['audience' => 'classrooms', 'classroom_ids' => [$this->a->id, $this->b->id]]))->assertCreated();

        $this->assertSame(3, $r->json('data.recipient_count'));
        $this->assertEquals(['5-A', '6-B'], $r->json('data.audience_detail'));
    }

    public function test_the_grade_range_and_the_section_list_are_checked(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'grades']))->assertUnprocessable()->assertJsonValidationErrors(['grade_from_id', 'grade_to_id']);
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'grades', 'grade_from_id' => 99999, 'grade_to_id' => $this->g5->id]))->assertUnprocessable();
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'classrooms', 'classroom_ids' => []]))->assertUnprocessable();
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'classrooms', 'classroom_ids' => [$this->a->id, $this->a->id]]))->assertUnprocessable();
    }

    public function test_a_teacher_may_pick_several_of_their_own_sections_but_not_grades_or_others(): void
    {
        $math = Subject::first();
        $second = Classroom::factory()->create(['name' => '5-C', 'academic_year_id' => $this->a->academic_year_id, 'grade_level_id' => $this->g5->id]);
        TeachingAssignment::create(['classroom_id' => $second->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 2]);
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'classrooms', 'classroom_ids' => [$this->a->id, $second->id]]))->assertCreated();
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'classrooms', 'classroom_ids' => [$this->a->id, $this->b->id]]))->assertForbidden(); // one of them is not theirs
        $this->postJson('/api/surveys', $this->pollBody(['audience' => 'grades', 'grade_from_id' => $this->g5->id, 'grade_to_id' => $this->g5->id]))->assertForbidden();
    }

    public function test_the_results_table_has_the_section_phone_decision_and_the_extra_answers(): void
    {
        $this->dad->update(['phone' => '0790000001']);
        $this->actAsAdmin();
        $id = $this->postJson('/api/surveys', $this->approvalBody(['audience' => 'all', 'classroom_ids' => null]))->json('data.id');
        Sanctum::actingAs($this->dad);
        $rows = $this->mine();
        $ali = $rows->first(fn ($r) => $r['student']['name_en'] === 'ali');
        $this->postJson("/api/my-surveys/{$ali['id']}/respond", ['decision' => 'approved', 'answers' => [$ali['questions'][0]['id'] => '0791111111']])->assertOk();
        $dana = $rows->first(fn ($r) => $r['student']['name_en'] === 'dana');
        $this->postJson("/api/my-surveys/{$dana['id']}/respond", ['decision' => 'declined'])->assertOk();

        $this->actAsAdmin();
        $table = collect($this->getJson("/api/surveys/$id")->json('data.recipients'));
        $this->assertSame(4, $table->count());
        $row = $table->first(fn ($r) => $r['student']['name_en'] === 'ali');
        $this->assertSame(['Abu Ali', '0790000001', '5-A', 'approved'], [$row['parent'], $row['phone'], $row['classroom'], $row['state']]);
        $this->assertSame('0791111111', array_values($row['answers'])[0]);
        $this->assertSame('declined', $table->first(fn ($r) => $r['student']['name_en'] === 'dana')['state']);
        $this->assertSame(['pending', 'pending'], $table->take(2)->pluck('state')->all()); // those still waiting come first
    }

    /** @return list<list<mixed>> the rows of the downloaded workbook */
    private function sheet($response): array
    {
        $response->assertOk();
        $reader = new Reader();
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    public function test_the_responses_download_as_excel_and_can_be_limited_to_one_state(): void
    {
        $this->dad->update(['phone' => '0790000001']);
        $this->actAsAdmin();
        $id = $this->postJson('/api/surveys', $this->approvalBody(['audience' => 'all', 'classroom_ids' => null]))->json('data.id');
        Sanctum::actingAs($this->dad);
        $ali = $this->mine()->first(fn ($r) => $r['student']['name_en'] === 'ali');
        $this->postJson("/api/my-surveys/{$ali['id']}/respond", ['decision' => 'approved', 'answers' => [$ali['questions'][0]['id'] => '0791111111']])->assertOk();

        $this->actAsAdmin();
        $all = $this->sheet($this->get("/api/surveys/$id/export", ['Accept-Language' => 'en']));
        $this->assertSame(['Parent', 'Phone', 'Student', 'Section', 'Answer', 'Answered at', 'Emergency phone'], $all[0]);
        $this->assertCount(5, $all); // header + 4 children

        $approved = $this->sheet($this->get("/api/surveys/$id/export?state=approved", ['Accept-Language' => 'en']));
        $this->assertCount(2, $approved);
        $this->assertSame(['Abu Ali', '0790000001', 'ali', '5-A', 'Approved'], array_slice($approved[1], 0, 5));
        $this->assertSame('0791111111', $approved[1][6]);

        $arabic = $this->sheet($this->get("/api/surveys/$id/export?state=approved", ['Accept-Language' => 'ar']));
        $this->assertSame('ولي الأمر', $arabic[0][0]);

        $this->get("/api/surveys/$id/export?state=nonsense")->assertStatus(422);
    }

    public function test_only_the_creator_or_the_admin_can_download_the_responses(): void
    {
        Sanctum::actingAs($this->teacher);
        $mine = $this->postJson('/api/surveys', $this->approvalBody())->json('data.id');
        $this->get("/api/surveys/$mine/export")->assertOk();

        $this->actAsAdmin();
        $theirs = $this->postJson('/api/surveys', $this->pollBody())->json('data.id');
        Sanctum::actingAs($this->teacher);
        $this->get("/api/surveys/$theirs/export")->assertForbidden();
        Sanctum::actingAs($this->dad);
        $this->get("/api/surveys/$mine/export")->assertForbidden();
    }
}
