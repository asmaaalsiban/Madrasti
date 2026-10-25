<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Communication\Models\Conversation;
use App\Modules\Communication\Models\Message;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class ChatTest extends AcademicTestCase
{
    private Classroom $a;

    private Classroom $b;

    private User $teacherA;

    private User $teacherB;

    private User $studentA;

    private User $studentB;

    private User $parentA;

    private User $parentB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $math = Subject::factory()->create(['name_ar' => 'رياضيات', 'name_en' => 'Math']);
        $this->a = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $this->b = Classroom::factory()->create(['name' => '5-B', 'academic_year_id' => $year->id]);
        $this->admin = User::factory()->role(Role::Admin)->create(['name' => 'Admin']);
        $this->teacherA = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create(['name' => 'Teacher A']);
        $this->teacherB = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create(['name' => 'Teacher B']);
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacherA->id, 'weekly_periods' => 4]);
        TeachingAssignment::create(['classroom_id' => $this->b->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacherB->id, 'weekly_periods' => 4]);

        foreach (['a' => $this->a, 'b' => $this->b] as $key => $room) {
            $user = User::factory()->role(Role::Student)->create(['name' => "Student $key"]);
            $student = Student::create(['student_number' => "S-$key", 'name_ar' => "طالب $key", 'name_en' => "Student $key", 'gender' => 'male', 'user_id' => $user->id]);
            Enrollment::create(['student_id' => $student->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);
            $parent = User::factory()->role(Role::Guardian)->create(['name' => "Parent $key"]);
            $student->guardians()->attach($parent->id);
            $this->{'student'.strtoupper($key)} = $user;
            $this->{'parent'.strtoupper($key)} = $parent;
        }
    }

    private function open(User $from, User $to)
    {
        Sanctum::actingAs($from);

        return $this->postJson('/api/chat/conversations', ['user_id' => $to->id]);
    }

    private function say(int $conversation, string $text = 'Hello')
    {
        return $this->postJson("/api/chat/conversations/$conversation/messages", ['body' => $text]);
    }

    public function test_who_can_start_a_chat_with_whom(): void
    {
        $this->open($this->teacherA, $this->parentA)->assertCreated();
        $this->open($this->teacherA, $this->studentA)->assertCreated();
        $this->open($this->parentA, $this->teacherA)->assertOk(); // the same chat, opened from the other side
        $this->open($this->studentA, $this->teacherA)->assertOk();
        $this->open($this->parentB, $this->admin)->assertCreated();
        $this->open($this->admin, $this->studentB)->assertCreated();

        $this->open($this->teacherA, $this->parentB)->assertForbidden(); // not their section
        $this->open($this->teacherB, $this->studentA)->assertForbidden();
        $this->open($this->studentA, $this->parentA)->assertForbidden(); // students never write to parents or students
        $this->open($this->studentA, $this->studentB)->assertForbidden();
        $this->open($this->parentA, $this->parentB)->assertForbidden();
        $this->open($this->teacherA, $this->teacherB)->assertForbidden();
        $this->open($this->teacherA, $this->teacherA)->assertForbidden();
        $this->assertSame(4, Conversation::count());
    }

    public function test_the_contacts_are_the_people_one_may_chat_with_and_say_who_they_are(): void
    {
        Sanctum::actingAs($this->parentA);
        $contacts = collect($this->getJson('/api/chat/contacts')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['Teacher A', 'Admin'], $contacts->pluck('name')->all());
        $this->assertSame('رياضيات', $contacts->firstWhere('name', 'Teacher A')['label']);

        Sanctum::actingAs($this->teacherA);
        $mine = collect($this->getJson('/api/chat/contacts')->json('data'));
        $this->assertEqualsCanonicalizing(['Student a', 'Parent a', 'Admin'], $mine->pluck('name')->all());
        $this->assertSame('5-A', $mine->firstWhere('name', 'Student a')['label']);
        $this->assertSame('طالب a', $mine->firstWhere('name', 'Parent a')['label']);
        $this->assertSame(['Parent a'], collect($this->getJson('/api/chat/contacts?search=parent')->json('data'))->pluck('name')->all());

        Sanctum::actingAs($this->studentA);
        $this->assertEqualsCanonicalizing(['Teacher A', 'Admin'], collect($this->getJson('/api/chat/contacts')->json('data'))->pluck('name')->all());

        Sanctum::actingAs($this->admin);
        $this->assertCount(6, $this->getJson('/api/chat/contacts')->json('data'));
    }

    public function test_messages_are_sent_listed_and_only_the_participants_see_them(): void
    {
        $id = $this->open($this->parentA, $this->teacherA)->json('data.id');
        $this->say($id, 'Hello teacher')->assertCreated()->assertJsonPath('data.mine', true)->assertJsonPath('data.read', false);
        Sanctum::actingAs($this->teacherA);
        $this->say($id, 'Hello parent')->assertCreated();

        $list = $this->getJson("/api/chat/conversations/$id/messages")->assertOk()->json('data');
        $this->assertSame(['Hello teacher', 'Hello parent'], array_column($list, 'body'));

        foreach ([$this->parentB, $this->studentA, $this->teacherB] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson("/api/chat/conversations/$id/messages")->assertNotFound();
            $this->say($id, 'intruder')->assertNotFound();
        }
        $this->assertSame(2, Message::count());
    }

    public function test_read_receipts_unread_counters_and_the_conversation_list(): void
    {
        $id = $this->open($this->parentA, $this->teacherA)->json('data.id');
        $first = $this->say($id, 'One')->json('data.id');
        $this->say($id, 'Two');

        Sanctum::actingAs($this->teacherA);
        $this->getJson('/api/chat/unread-count')->assertJsonPath('data.count', 2);
        $row = $this->getJson('/api/chat/conversations')->assertOk()->json('data.0');
        $this->assertSame(2, $row['unread']);
        $this->assertSame('Parent a', $row['title']);
        $this->assertSame('Two', $row['last_message']['body']);

        $this->getJson("/api/chat/conversations/$id/messages"); // opening reads them
        $this->getJson('/api/chat/unread-count')->assertJsonPath('data.count', 0);

        Sanctum::actingAs($this->parentA);
        $messages = $this->getJson("/api/chat/conversations/$id/messages")->json('data');
        $this->assertSame([true, true], array_column($messages, 'read'));
        $this->assertSame(1, $messages[0]['read_by']);
        $this->assertSame($first, $messages[0]['id']);
    }

    public function test_polling_returns_only_new_messages(): void
    {
        $id = $this->open($this->studentA, $this->teacherA)->json('data.id');
        $one = $this->say($id, 'One')->json('data.id');
        $this->say($id, 'Two');

        $new = $this->getJson("/api/chat/conversations/$id/messages?after_id=$one")->assertOk()->json('data');
        $this->assertSame(['Two'], array_column($new, 'body'));
    }

    public function test_a_message_needs_text_or_a_photo_and_a_photo_is_private_to_the_chat(): void
    {
        Storage::fake();
        $id = $this->open($this->parentA, $this->teacherA)->json('data.id');
        $this->say($id, '')->assertUnprocessable()->assertJsonValidationErrors('body');

        $sent = $this->post("/api/chat/conversations/$id/messages", ['image' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.has_image', true);
        $this->post("/api/chat/conversations/$id/messages", ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();

        $mid = $sent->json('data.id');
        $this->get("/api/chat/messages/$mid/image")->assertOk();
        Sanctum::actingAs($this->teacherA);
        $this->get("/api/chat/messages/$mid/image")->assertOk();
        Sanctum::actingAs($this->parentB);
        $this->get("/api/chat/messages/$mid/image")->assertNotFound();
    }

    public function test_a_sender_deletes_only_their_own_message(): void
    {
        $id = $this->open($this->parentA, $this->teacherA)->json('data.id');
        $mid = $this->say($id, 'Oops')->json('data.id');

        Sanctum::actingAs($this->teacherA);
        $this->deleteJson("/api/chat/messages/$mid")->assertForbidden();
        Sanctum::actingAs($this->parentA);
        $this->deleteJson("/api/chat/messages/$mid")->assertNoContent();
        $this->assertSame([], $this->getJson("/api/chat/conversations/$id/messages")->json('data'));
    }

    public function test_a_chat_stops_when_the_pair_is_no_longer_allowed(): void
    {
        $id = $this->open($this->parentA, $this->teacherA)->json('data.id');
        TeachingAssignment::query()->delete();
        Classroom::whereKey($this->a->id)->update(['homeroom_teacher_id' => null]);

        Sanctum::actingAs($this->parentA);
        $this->say($id, 'still there?')->assertForbidden();
        $this->getJson("/api/chat/conversations/$id/messages")->assertOk(); // the history can still be read
    }

    public function test_a_teacher_makes_a_group_for_a_section_with_its_students_and_teachers(): void
    {
        Sanctum::actingAs($this->teacherA);
        $id = $this->postJson('/api/chat/groups', ['classroom_id' => $this->a->id, 'title' => 'Math 5-A'])->assertCreated()
            ->assertJsonPath('data.title', 'Math 5-A')->assertJsonPath('data.members', 2)->json('data.id'); // the student and the teacher

        $this->say($id, 'Homework is on the site')->assertCreated();
        Sanctum::actingAs($this->studentA);
        $this->assertSame(['Homework is on the site'], array_column($this->getJson("/api/chat/conversations/$id/messages")->json('data'), 'body'));
        $this->say($id, 'Thanks')->assertCreated(); // students may write by default

        foreach ([$this->studentB, $this->parentA, $this->teacherB] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson("/api/chat/conversations/$id/messages")->assertNotFound();
        }
    }

    public function test_a_group_can_include_parents_and_can_be_limited_to_the_teachers(): void
    {
        Sanctum::actingAs($this->teacherA);
        $id = $this->postJson('/api/chat/groups', ['classroom_id' => $this->a->id, 'include_parents' => true, 'members_can_post' => false])->assertCreated()
            ->assertJsonPath('data.title', '5-A')->assertJsonPath('data.members', 3)->json('data.id');

        $this->say($id, 'Trip on Sunday')->assertCreated();
        Sanctum::actingAs($this->parentA);
        $this->getJson("/api/chat/conversations/$id")->assertOk()->assertJsonPath('data.can_post', false);
        $this->say($id, 'Can I write?')->assertForbidden();
        Sanctum::actingAs($this->studentA);
        $this->say($id, 'me too')->assertForbidden();
        $this->assertSame(1, Message::count());
    }

    public function test_a_group_follows_the_section_when_students_join_or_leave(): void
    {
        Sanctum::actingAs($this->teacherA);
        $id = $this->postJson('/api/chat/groups', ['classroom_id' => $this->a->id])->json('data.id');

        $newUser = User::factory()->role(Role::Student)->create();
        $new = Student::create(['student_number' => 'S-n', 'name_ar' => 'جديد', 'name_en' => 'New', 'gender' => 'male', 'user_id' => $newUser->id]);
        Enrollment::create(['student_id' => $new->id, 'classroom_id' => $this->a->id, 'academic_year_id' => $this->a->academic_year_id]);
        Enrollment::where('student_id', Student::where('user_id', $this->studentA->id)->value('id'))->delete();

        $this->getJson("/api/chat/conversations/$id")->assertOk()->assertJsonPath('data.members', 2);
        Sanctum::actingAs($newUser);
        $this->getJson("/api/chat/conversations/$id/messages")->assertOk();
        Sanctum::actingAs($this->studentA);
        $this->getJson("/api/chat/conversations/$id/messages")->assertNotFound();
    }

    public function test_only_a_teacher_of_the_section_or_the_admin_makes_a_group(): void
    {
        Sanctum::actingAs($this->teacherA);
        $this->postJson('/api/chat/groups', ['classroom_id' => $this->b->id])->assertUnprocessable()->assertJsonValidationErrors('classroom_id');
        Sanctum::actingAs($this->parentA);
        $this->postJson('/api/chat/groups', ['classroom_id' => $this->a->id])->assertForbidden();
        Sanctum::actingAs($this->studentA);
        $this->postJson('/api/chat/groups', ['classroom_id' => $this->a->id])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/chat/groups', ['classroom_id' => $this->b->id])->assertCreated();
    }

    public function test_a_message_rings_the_bell_once_until_the_chat_is_opened(): void
    {
        $id = $this->open($this->parentA, $this->teacherA)->json('data.id');
        $this->say($id, 'One');
        $this->say($id, 'Two');
        $this->say($id, 'Three');

        $bell = $this->teacherA->notifications()->get();
        $this->assertCount(1, $bell);
        $this->assertSame('chat_message', $bell[0]->data['kind']);
        $this->assertSame('Parent a', $bell[0]->data['params']['sender']);
        $this->assertSame($id, $bell[0]->data['params']['conversation']);
        $this->assertSame('/chat?c='.$id, $bell[0]->data['link']);
        $this->assertSame(0, $this->parentA->notifications()->count()); // not for the sender

        Sanctum::actingAs($this->teacherA);
        $this->getJson("/api/chat/conversations/$id/messages"); // opening marks the bell read
        $this->assertSame(0, $this->teacherA->unreadNotifications()->count());

        Sanctum::actingAs($this->parentA);
        $this->say($id, 'Four');
        $this->assertSame(2, $this->teacherA->notifications()->count()); // a new one after it was read
    }

    public function test_the_unread_counter_ignores_deleted_messages_and_ones_already_read(): void
    {
        $id = $this->open($this->parentA, $this->teacherA)->json('data.id');
        $gone = $this->say($id, 'Oops')->json('data.id');
        $this->say($id, 'Real');
        $this->deleteJson("/api/chat/messages/$gone");

        Sanctum::actingAs($this->teacherA);
        $this->getJson('/api/chat/unread-count')->assertJsonPath('data.count', 1);
        $this->assertSame(0, DB::table('message_reads')->count());
    }
}
