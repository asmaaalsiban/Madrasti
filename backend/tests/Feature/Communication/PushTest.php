<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use App\Shared\Push\PushTransport;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class PushTest extends AcademicTestCase
{
    /** @var list<array{subs: array, payload: array}> */
    public array $sent = [];

    public array $gone = [];

    private User $teacher;

    private User $student;

    private User $parent;

    private Classroom $room;

    private Subject $math;

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $this->app->instance(PushTransport::class, new class($test) implements PushTransport
        {
            public function __construct(private PushTest $test) {}

            public function send(array $subscriptions, array $payload): array
            {
                $this->test->sent[] = ['subs' => $subscriptions, 'payload' => $payload];

                return $this->test->gone;
            }
        });
        config(['push.public_key' => 'PUBLIC', 'push.private_key' => 'PRIVATE']);

        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->math = Subject::factory()->create(['name_ar' => 'رياضيات', 'name_en' => 'Math']);
        $this->room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        TeachingAssignment::create(['classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        $this->student = User::factory()->role(Role::Student)->create(['locale' => 'en']);
        $record = Student::create(['student_number' => 'S-1', 'name_ar' => 'ليلى', 'name_en' => 'Layla', 'gender' => 'female', 'user_id' => $this->student->id]);
        Enrollment::create(['student_id' => $record->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id]);
        $this->parent = User::factory()->role(Role::Guardian)->create(['locale' => 'ar']);
        $record->guardians()->attach($this->parent->id);
    }

    private function subscribe(User $user, string $endpoint = 'https://push.example/abc'): void
    {
        Sanctum::actingAs($user);
        $this->postJson('/api/push/subscribe', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'P', 'auth' => 'A']])->assertOk();
    }

    private function homework(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/homework', ['classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'title' => 'Page 10', 'due_on' => '2026-11-01'])->assertCreated();
    }

    public function test_the_config_says_whether_push_is_available_and_gives_the_public_key(): void
    {
        Sanctum::actingAs($this->student);
        $this->getJson('/api/push/config')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.public_key', 'PUBLIC');

        config(['push.public_key' => null]);
        $this->getJson('/api/push/config')->assertJsonPath('data.enabled', false)->assertJsonPath('data.public_key', null);
    }

    public function test_a_subscription_is_saved_once_per_browser_and_can_be_removed(): void
    {
        $this->subscribe($this->student);
        $this->subscribe($this->student);
        $this->assertSame(1, DB::table('push_subscriptions')->count());

        $this->subscribe($this->parent); // the same browser, now another person
        $this->assertSame($this->parent->id, DB::table('push_subscriptions')->value('user_id'));

        $this->postJson('/api/push/unsubscribe', ['endpoint' => 'https://push.example/abc'])->assertNoContent();
        $this->assertSame(0, DB::table('push_subscriptions')->count());
    }

    public function test_a_subscription_needs_a_valid_endpoint_and_keys_and_one_cannot_remove_anothers(): void
    {
        Sanctum::actingAs($this->student);
        $this->postJson('/api/push/subscribe', ['endpoint' => 'nope', 'keys' => ['p256dh' => 'P', 'auth' => 'A']])->assertUnprocessable();
        $this->postJson('/api/push/subscribe', ['endpoint' => 'https://push.example/x'])->assertUnprocessable();

        $this->subscribe($this->parent);
        Sanctum::actingAs($this->student);
        $this->postJson('/api/push/unsubscribe', ['endpoint' => 'https://push.example/abc']);
        $this->assertSame(1, DB::table('push_subscriptions')->count());
    }

    public function test_new_homework_reaches_the_subscribed_phones_in_each_persons_language(): void
    {
        $this->subscribe($this->student, 'https://push.example/student');
        $this->subscribe($this->parent, 'https://push.example/parent');

        $this->homework();

        $bodies = collect($this->sent)->mapWithKeys(fn ($s) => [$s['subs'][0]['endpoint'] => $s['payload']['body']]);
        $this->assertSame('New homework in Math: Page 10', $bodies['https://push.example/student']);
        $this->assertSame('واجب جديد في رياضيات: Page 10 (ليلى)', $bodies['https://push.example/parent']);
        $this->assertSame('/student/homework', collect($this->sent)->first(fn ($s) => $s['subs'][0]['endpoint'] === 'https://push.example/student')['payload']['url']);
    }

    public function test_nobody_is_pushed_without_a_subscription_or_without_keys(): void
    {
        $this->homework();
        $this->assertSame([], $this->sent);

        $this->subscribe($this->student);
        config(['push.private_key' => null]);
        $this->homework();
        $this->assertSame([], $this->sent);
    }

    public function test_a_gone_subscription_is_forgotten_and_a_push_failure_never_breaks_the_action(): void
    {
        $this->subscribe($this->student, 'https://push.example/dead');
        $this->gone = ['https://push.example/dead'];

        $this->homework();
        $this->assertSame(0, DB::table('push_subscriptions')->count());

        $this->app->instance(PushTransport::class, new class implements PushTransport
        {
            public function send(array $subscriptions, array $payload): array
            {
                throw new \RuntimeException('push service down');
            }
        });
        $this->subscribe($this->student, 'https://push.example/alive');
        $this->homework(); // still created
        $this->assertSame(2, DB::table('homework_assignments')->count());
        $this->assertSame(2, $this->student->notifications()->count()); // the bell works either way
    }
}
