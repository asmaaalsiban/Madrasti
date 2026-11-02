<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Academic\AcademicTestCase;

class ParentPhoneLoginTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsAdmin();
    }

    private function addStudent(string $number, array $extra = [])
    {
        return $this->postJson('/api/students', ['student_number' => $number, 'name_ar' => 'طالب '.$number, ...$extra]);
    }

    public function test_a_parent_with_only_a_phone_gets_an_account_that_signs_in_with_it(): void
    {
        $this->addStudent('S-1', ['guardian_name' => 'Abu Ali', 'guardian_phone' => '0790 000 001'])->assertCreated();

        $parent = User::where('role', Role::Guardian)->firstOrFail();
        $this->assertNull($parent->email); // nothing is invented
        $this->assertSame('0790000001', $parent->phone);
        $this->assertSame('Abu Ali', $parent->name);
        $this->assertSame(1, Student::first()->guardians()->wherePivot('is_primary', true)->count());

        // Signs in with the number however it is typed, with the default password, and must then change it.
        foreach (['0790000001', '0790 000 001', '079-000-0001', '+962790000001', '00962790000001'] as $typed) {
            $this->postJson('/api/auth/login', ['login' => $typed, 'password' => config('accounts.default_password')])
                ->assertOk()->assertJsonPath('user.name', 'Abu Ali')->assertJsonPath('user.must_change_password', true);
        }
        $this->postJson('/api/auth/login', ['login' => '0790000001', 'password' => 'wrong-password'])->assertUnprocessable();
    }

    public function test_children_with_the_same_phone_share_one_parent_even_if_written_differently(): void
    {
        $this->addStudent('S-1', ['guardian_phone' => '0790000001'])->assertCreated();
        $this->addStudent('S-2', ['guardian_phone' => '+962 79 000 0001'])->assertCreated();

        $this->assertSame(1, User::where('role', Role::Guardian)->count());
        $this->assertSame(2, User::where('role', Role::Guardian)->first()->children()->count());
    }

    public function test_a_phone_that_belongs_to_staff_cannot_become_a_parent_account(): void
    {
        User::factory()->staff(StaffType::Teacher)->create(['phone' => '0791111111']);

        $this->addStudent('S-1', ['guardian_phone' => '0791111111'])->assertUnprocessable()->assertJsonValidationErrors('guardian_phone');
        $this->assertSame(0, Student::count());
    }

    public function test_adding_an_email_later_keeps_the_same_parent(): void
    {
        $id = $this->addStudent('S-1', ['guardian_phone' => '0790000001'])->json('data.id');
        $this->putJson("/api/students/$id", ['student_number' => 'S-1', 'name_ar' => 'طالب', 'guardian_email' => 'abu@example.com', 'guardian_phone' => '0790000001'])->assertOk();

        $this->assertSame(1, User::where('role', Role::Guardian)->count());
        $this->assertSame('abu@example.com', User::where('role', Role::Guardian)->first()->email);
    }

    public function test_forgot_password_sends_nothing_to_a_parent_without_an_email_and_says_the_same(): void
    {
        Mail::fake();
        $this->addStudent('S-1', ['guardian_phone' => '0790000001']);

        $real = $this->postJson('/api/auth/forgot-password', ['login' => '0790000001'])->assertOk()->json();
        $unknown = $this->postJson('/api/auth/forgot-password', ['login' => '0799999999'])->assertOk()->json();

        $this->assertSame($unknown, $real);
        Mail::assertNothingSent();
    }

    public function test_the_admin_can_reset_such_a_parents_password(): void
    {
        $this->addStudent('S-1', ['guardian_phone' => '0790000001']);
        $parent = User::where('role', Role::Guardian)->first();
        $parent->forceFill(['password' => 'Changed12345', 'must_change_password' => false])->save();

        $this->postJson("/api/users/{$parent->id}/reset-password")->assertOk();
        $this->postJson('/api/auth/login', ['login' => '0790000001', 'password' => config('accounts.default_password')])->assertOk();
    }

    public function test_two_accounts_on_the_same_line_match_nobody_by_a_loose_number(): void
    {
        User::factory()->role(Role::Guardian)->create(['phone' => '0790000001']);
        User::factory()->role(Role::Guardian)->create(['phone' => '+962790000001']);

        $this->assertNull(User::findByLogin('790000001')); // ambiguous
        $this->assertNotNull(User::findByLogin('0790000001')); // exact still works
    }

    public function test_emails_still_sign_in_in_any_letter_case(): void
    {
        User::factory()->role(Role::Guardian)->create(['email' => 'dad@example.com', 'password' => 'Secret12345', 'must_change_password' => false]);
        $this->postJson('/api/auth/login', ['login' => 'Dad@Example.com', 'password' => 'Secret12345'])->assertOk();
    }
}
