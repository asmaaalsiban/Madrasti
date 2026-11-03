<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Communication\Models\Story;
use App\Modules\Communication\Models\StoryMedia;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class StoryTest extends AcademicTestCase
{
    private User $admin;

    private User $teacher;

    private User $other;

    private Classroom $a;

    private Classroom $b;

    private Classroom $c;

    private User $dad;

    private User $mum;

    private User $kid;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $g5 = GradeLevel::factory()->create(['sort_order' => 5]);
        $g6 = GradeLevel::factory()->create(['sort_order' => 6]);
        $this->a = Classroom::factory()->create(['name' => '5-A', 'grade_level_id' => $g5->id, 'academic_year_id' => $year->id]);
        $this->b = Classroom::factory()->create(['name' => '5-B', 'grade_level_id' => $g5->id, 'academic_year_id' => $year->id]);
        $this->c = Classroom::factory()->create(['name' => '6-A', 'grade_level_id' => $g6->id, 'academic_year_id' => $year->id]);
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara']);
        $this->other = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Omar']);
        $subject = Subject::factory()->create();
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $subject->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 3]);
        TeachingAssignment::create(['classroom_id' => $this->c->id, 'subject_id' => $subject->id, 'teacher_id' => $this->other->id, 'weekly_periods' => 3]);
        $this->dad = User::factory()->role(Role::Guardian)->create(['name' => 'Abu Ali']);
        $this->mum = User::factory()->role(Role::Guardian)->create(['name' => 'Um Sami']);
        $this->kid = User::factory()->role(Role::Student)->create(['name' => 'Ali']);
        foreach ([['Ali', $this->a, $this->dad, $this->kid], ['Sami', $this->b, $this->mum, null]] as [$name, $room, $parent, $account]) {
            $s = Student::create(['student_number' => "S-$name", 'name_ar' => $name, 'name_en' => $name, 'user_id' => $account?->id]);
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $room->id, 'academic_year_id' => $room->academic_year_id]);
            $s->guardians()->attach($parent->id);
        }
    }

    private function image(string $name = 'a.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 400)->size(300);
    }

    private function publish(User $by, array $data, array $images = [])
    {
        Sanctum::actingAs($by);

        return $this->post('/api/stories', $data + ($images ? ['images' => $images] : []), ['Accept' => 'application/json']);
    }

    private function feed(User $user): array
    {
        Sanctum::actingAs($user);

        return collect($this->getJson('/api/stories')->assertOk()->json('data'))->pluck('body')->all();
    }

    public function test_a_teacher_posts_to_their_class_and_only_its_students_and_parents_see_it(): void
    {
        $this->publish($this->teacher, ['audience' => 'classroom', 'classroom_id' => $this->a->id, 'body' => 'Trip photos!'], [$this->image(), $this->image('b.jpg')])->assertCreated()->assertJsonPath('data.author.name', 'Sara')->assertJsonCount(2, 'data.media')->assertJsonPath('data.target.name_en', '5-A');
        $this->assertSame(['Trip photos!'], $this->feed($this->dad));
        $this->assertSame(['Trip photos!'], $this->feed($this->kid));
        $this->assertSame([], $this->feed($this->mum)); // her child is in 5-B
        $this->assertSame(['Trip photos!'], $this->feed($this->teacher));
        $this->assertSame([], $this->feed($this->other)); // another teacher, another class
        $this->assertSame(['Trip photos!'], $this->feed($this->admin));
    }

    public function test_the_principal_posts_to_a_grade_or_the_whole_school(): void
    {
        $this->publish($this->admin, ['audience' => 'grade', 'grade_level_id' => $this->a->grade_level_id, 'body' => 'Grade 5 news'])->assertCreated();
        $this->publish($this->admin, ['audience' => 'school', 'body' => 'Holiday on Monday'])->assertCreated();
        $this->assertEqualsCanonicalizing(['Grade 5 news', 'Holiday on Monday'], $this->feed($this->dad));
        $this->assertEqualsCanonicalizing(['Grade 5 news', 'Holiday on Monday'], $this->feed($this->mum)); // 5-B is grade 5 too
        $this->assertEqualsCanonicalizing(['Holiday on Monday'], $this->feed($this->other)); // teaches 6-A only
        $this->assertEqualsCanonicalizing(['Grade 5 news', 'Holiday on Monday'], $this->feed($this->kid)); // 5-A
    }

    public function test_a_teacher_cannot_post_outside_their_classes_and_a_post_needs_something_in_it(): void
    {
        $this->publish($this->teacher, ['audience' => 'classroom', 'classroom_id' => $this->b->id, 'body' => 'x'])->assertForbidden(); // not their class
        $this->publish($this->teacher, ['audience' => 'school', 'body' => 'x'])->assertForbidden();
        $this->publish($this->teacher, ['audience' => 'grade', 'grade_level_id' => $this->a->grade_level_id, 'body' => 'x'])->assertForbidden();
        $this->publish($this->teacher, ['audience' => 'classroom', 'classroom_id' => $this->a->id])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->publish($this->teacher, ['audience' => 'classroom', 'classroom_id' => $this->a->id, 'body' => 'x'], array_map(fn ($i) => $this->image("$i.jpg"), range(1, 7)))->assertUnprocessable(); // at most 6 photos
        $this->publish($this->dad, ['audience' => 'school', 'body' => 'x'])->assertForbidden(); // parents read, react and comment
        Sanctum::actingAs($this->dad);
        $this->getJson('/api/stories/targets')->assertForbidden();
        Sanctum::actingAs($this->teacher);
        $t = $this->getJson('/api/stories/targets')->assertOk()->json('data');
        $this->assertSame(['5-A'], collect($t['classrooms'])->pluck('name')->all());
        $this->assertFalse($t['school']);
        Sanctum::actingAs($this->admin);
        $this->assertTrue($this->getJson('/api/stories/targets')->json('data.school'));
    }

    public function test_the_class_is_told_and_photos_open_only_for_people_who_may_see_the_story(): void
    {
        $id = $this->publish($this->teacher, ['audience' => 'classroom', 'classroom_id' => $this->a->id, 'body' => 'Hello'], [$this->image()])->assertCreated()->json('data.id');
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->dad->id)->where('data', 'like', '%story_new%')->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->kid->id)->where('data', 'like', '%story_new%')->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->mum->id)->count());

        $media = Story::find($id)->media->first();
        Storage::assertExists($media->path);
        Sanctum::actingAs($this->dad);
        $this->get("/api/stories/media/{$media->id}")->assertOk();
        Sanctum::actingAs($this->mum);
        $this->get("/api/stories/media/{$media->id}")->assertNotFound();
    }

    public function test_hearts_toggle_and_comments_work_for_readers_and_can_be_closed(): void
    {
        $id = $this->publish($this->teacher, ['audience' => 'classroom', 'classroom_id' => $this->a->id, 'body' => 'Hello'])->json('data.id');
        Sanctum::actingAs($this->dad);
        $this->postJson("/api/stories/$id/react")->assertOk()->assertJsonPath('data.liked', true)->assertJsonPath('data.count', 1);
        Sanctum::actingAs($this->kid);
        $this->postJson("/api/stories/$id/react")->assertJsonPath('data.count', 2);
        Sanctum::actingAs($this->dad);
        $this->postJson("/api/stories/$id/react")->assertJsonPath('data.liked', false)->assertJsonPath('data.count', 1); // taken back
        $c = $this->postJson("/api/stories/$id/comments", ['body' => 'Lovely!'])->assertCreated()->assertJsonPath('data.can_delete', true)->json('data');
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->teacher->id)->where('data', 'like', '%story_comment%')->count());
        $this->postJson("/api/stories/$id/comments", ['body' => ''])->assertUnprocessable();

        Sanctum::actingAs($this->mum); // not in the class
        $this->postJson("/api/stories/$id/react")->assertNotFound();
        $this->postJson("/api/stories/$id/comments", ['body' => 'hi'])->assertNotFound();
        $this->getJson("/api/stories/$id/comments")->assertNotFound();

        Sanctum::actingAs($this->kid);
        $this->deleteJson("/api/stories/comments/{$c['id']}")->assertForbidden(); // someone else's comment
        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/stories/$id/comments")->assertOk()->assertJsonPath('data.0.can_delete', true);
        $this->putJson("/api/stories/$id", ['comments_enabled' => false])->assertOk()->assertJsonPath('data.comments_enabled', false);
        Sanctum::actingAs($this->dad);
        $this->postJson("/api/stories/$id/comments", ['body' => 'again'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->deleteJson("/api/stories/comments/{$c['id']}")->assertNoContent(); // my own
    }

    public function test_pinned_posts_come_first_and_only_the_author_or_principal_manage_a_post_and_its_files_go_with_it(): void
    {
        $first = $this->publish($this->teacher, ['audience' => 'classroom', 'classroom_id' => $this->a->id, 'body' => 'First'], [$this->image()])->json('data.id');
        $this->publish($this->teacher, ['audience' => 'classroom', 'classroom_id' => $this->a->id, 'body' => 'Second']);
        $this->assertSame(['Second', 'First'], $this->feed($this->dad)); // newest first
        Sanctum::actingAs($this->teacher);
        $this->putJson("/api/stories/$first", ['is_pinned' => true])->assertOk()->assertJsonPath('data.is_pinned', true);
        $this->assertSame(['First', 'Second'], $this->feed($this->dad));

        Sanctum::actingAs($this->dad);
        $this->putJson("/api/stories/$first", ['is_pinned' => false])->assertForbidden();
        $this->deleteJson("/api/stories/$first")->assertForbidden();
        Sanctum::actingAs($this->other);
        $this->deleteJson("/api/stories/$first")->assertForbidden();

        $path = StoryMedia::where('story_id', $first)->value('path');
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/stories/$first")->assertNoContent(); // the principal
        Storage::assertMissing($path);
        $this->assertSame(['Second'], $this->feed($this->dad));
    }

    public function test_the_feed_is_paged(): void
    {
        foreach (range(1, 10) as $i) {
            $this->publish($this->teacher, ['audience' => 'classroom', 'classroom_id' => $this->a->id, 'body' => "Post $i"]);
        }
        Sanctum::actingAs($this->dad);
        $first = $this->getJson('/api/stories')->assertOk()->assertJsonPath('meta.last_page', 2)->json('data');
        $this->assertCount(8, $first);
        $this->assertCount(2, $this->getJson('/api/stories?page=2')->json('data'));
        $this->assertFalse($this->getJson('/api/stories')->json('can_post'));
    }
}
