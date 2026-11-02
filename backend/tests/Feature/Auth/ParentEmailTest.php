<?php

namespace Tests\Feature\Auth;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class ParentEmailTest extends AcademicTestCase
{
    private User $phoneParent;

    private Student $ali;

    private Student $sami;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->ali = Student::create(['student_number' => 'S-1', 'name_ar' => 'علي', 'gender' => 'male']);
        $this->sami = Student::create(['student_number' => 'S-2', 'name_ar' => 'سامي', 'gender' => 'male']);
        $this->phoneParent = User::factory()->role(Role::Guardian)->create(['name' => 'Abu Ali', 'email' => null, 'phone' => '0790000001', 'password' => 'Chosen12345', 'must_change_password' => false]);
        $this->ali->guardians()->attach($this->phoneParent->id, ['is_primary' => true]);
        Sanctum::actingAs($this->phoneParent);
    }

    private function codeSentTo(string $email): string
    {
        $code = null;
        Mail::assertSent(EmailVerificationCodeMail::class, function ($mail) use ($email, &$code) {
            $code = $mail->code;

            return $mail->hasTo($email);
        });

        return $code;
    }

    public function test_a_phone_parent_adds_a_verified_email(): void
    {
        $this->postJson('/api/auth/email/request', ['email' => 'Dad@Example.com'])->assertOk();
        $code = $this->codeSentTo('dad@example.com');

        $this->assertNull($this->phoneParent->fresh()->email); // not saved before the code is entered
        $this->postJson('/api/auth/email/confirm', ['email' => 'dad@example.com', 'code' => $code])->assertOk()->assertJsonPath('merged', false)->assertJsonPath('user.email', 'dad@example.com');

        $this->assertSame('dad@example.com', $this->phoneParent->fresh()->email);
        $this->assertSame(0, DB::table('email_verifications')->count());
        // The parent can now ask for a password code by email too.
        $this->postJson('/api/auth/forgot-password', ['login' => 'dad@example.com'])->assertOk();
        Mail::assertSent(\App\Mail\PasswordResetCodeMail::class);
    }

    public function test_a_wrong_code_does_nothing_and_five_wrong_tries_lock_it(): void
    {
        $this->postJson('/api/auth/email/request', ['email' => 'dad@example.com']);
        $code = $this->codeSentTo('dad@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/email/confirm', ['email' => 'dad@example.com', 'code' => $wrong])->assertUnprocessable();
        }
        $this->postJson('/api/auth/email/confirm', ['email' => 'dad@example.com', 'code' => $code])->assertUnprocessable(); // even the right one is locked
        $this->assertNull($this->phoneParent->fresh()->email);
    }

    public function test_the_code_is_for_the_email_it_was_sent_to_and_expires(): void
    {
        $this->postJson('/api/auth/email/request', ['email' => 'dad@example.com']);
        $code = $this->codeSentTo('dad@example.com');
        $this->postJson('/api/auth/email/confirm', ['email' => 'other@example.com', 'code' => $code])->assertUnprocessable();

        $this->travel(16)->minutes();
        $this->postJson('/api/auth/email/confirm', ['email' => 'dad@example.com', 'code' => $code])->assertUnprocessable();
    }

    public function test_an_email_that_already_belongs_to_another_parent_merges_the_two_accounts(): void
    {
        $other = User::factory()->role(Role::Guardian)->create(['name' => 'Abu Sami', 'email' => 'dad@example.com', 'phone' => null, 'password' => 'OldPassword12']);
        $this->sami->guardians()->attach($other->id, ['is_primary' => true]);
        $this->ali->guardians()->attach($other->id, ['is_primary' => false]); // already linked to Ali too
        DB::table('notifications')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $this->phoneParent->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/auth/email/request', ['email' => 'dad@example.com'])->assertOk();
        $response = $this->postJson('/api/auth/email/confirm', ['email' => 'dad@example.com', 'code' => $this->codeSentTo('dad@example.com')])->assertOk()->assertJsonPath('merged', true);

        $this->assertNull(User::withTrashed()->find($this->phoneParent->id)); // the phone-only account is gone
        $kept = $other->fresh();
        $this->assertEqualsCanonicalizing([$this->ali->id, $this->sami->id], $kept->children()->pluck('students.id')->all()); // both children, Ali once
        $this->assertSame('0790000001', $kept->phone);
        $this->assertTrue(Hash::check('Chosen12345', $kept->password)); // the password they just chose
        $this->assertSame(1, DB::table('guardian_student')->where('student_id', $this->ali->id)->count());
        $this->assertSame(1, DB::table('guardian_student')->where('student_id', $this->ali->id)->where('is_primary', true)->count()); // Ali still has a primary parent
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $kept->id)->count());
        $this->assertSame($kept->id, PersonalAccessToken::findToken($response->json('token'))->tokenable_id);

        // Signing in with the old phone number now opens the kept account.
        $this->postJson('/api/auth/login', ['login' => '0790000001', 'password' => 'Chosen12345'])->assertOk()->assertJsonPath('user.email', 'dad@example.com')->assertJsonCount(2, 'user.children');
    }

    public function test_an_email_of_staff_a_student_or_a_deleted_account_is_never_taken(): void
    {
        $staff = User::factory()->staff(StaffType::Teacher)->create(['email' => 'teacher@example.com']);
        $gone = User::factory()->role(Role::Guardian)->create(['email' => 'gone@example.com']);
        $gone->delete();

        foreach (['teacher@example.com', 'gone@example.com'] as $email) {
            $this->postJson('/api/auth/email/request', ['email' => $email])->assertOk(); // same answer, nothing leaks
        }
        Mail::assertNothingSent();
        $this->postJson('/api/auth/email/confirm', ['email' => 'teacher@example.com', 'code' => '123456'])->assertUnprocessable();
        $this->assertNotNull($staff->fresh());
    }

    public function test_only_a_parent_without_an_email_who_already_chose_a_password_can_use_it(): void
    {
        $this->phoneParent->forceFill(['must_change_password' => true])->save();
        $this->postJson('/api/auth/email/request', ['email' => 'dad@example.com'])->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create(['email' => 'has@example.com', 'must_change_password' => false]));
        $this->postJson('/api/auth/email/request', ['email' => 'dad@example.com'])->assertForbidden();

        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create(['must_change_password' => false]));
        $this->postJson('/api/auth/email/request', ['email' => 'dad@example.com'])->assertForbidden();
        Mail::assertNothingSent();
    }
}
