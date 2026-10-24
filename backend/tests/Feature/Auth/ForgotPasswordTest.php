<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Tests\TenantTestCase;

class ForgotPasswordTest extends TenantTestCase
{
    private User $studentUser;

    private User $parent;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([InitializeTenancyByRequestData::class]);
        RateLimiter::clear('x');
        Mail::fake();

        $this->studentUser = User::factory()->role(Role::Student)->create(['email' => 'ahmad@school.test', 'password' => 'OldPassword1']);
        $student = Student::create(['student_number' => 'S-1', 'name_ar' => 'أحمد خالد', 'name_en' => 'Ahmad Khaled', 'gender' => 'male', 'user_id' => $this->studentUser->id]);
        $this->parent = User::factory()->role(Role::Guardian)->create(['email' => 'father@mail.test']);
        $student->guardians()->attach($this->parent->id, ['is_primary' => true]);
        $this->teacher = User::factory()->staff(StaffType::Teacher)->create(['email' => 'sara@school.test', 'password' => 'OldPassword1']);
    }

    private function request(string $login)
    {
        return $this->postJson('/api/auth/forgot-password', ['login' => $login]);
    }

    private function sentCode(string $to): string
    {
        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use ($to, &$code) {
            if ($mail->hasTo($to)) {
                $code = $mail->code;
            }

            return $mail->hasTo($to);
        });

        return $code;
    }

    private function reset(string $login, string $code, string $password = 'BrandNewPass1')
    {
        return $this->postJson('/api/auth/reset-password', ['login' => $login, 'code' => $code, 'password' => $password, 'password_confirmation' => $password]);
    }

    public function test_a_student_gets_the_code_on_their_parents_email(): void
    {
        $this->request('ahmad@school.test')->assertOk()->assertJsonPath('sent', true);

        Mail::assertSent(PasswordResetCodeMail::class, 1);
        $code = $this->sentCode('father@mail.test');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Mail::assertNotSent(PasswordResetCodeMail::class, fn ($m) => $m->hasTo('ahmad@school.test'));
        $this->assertNotSame($code, DB::table('password_reset_codes')->value('code_hash'), 'the code is stored hashed');
    }

    public function test_anyone_else_gets_the_code_on_their_own_email_and_the_answer_is_the_same_for_unknown_accounts(): void
    {
        $this->request('sara@school.test')->assertOk();
        $this->sentCode('sara@school.test');

        $this->request('nobody@school.test')->assertOk()->assertJsonPath('sent', true);
        Mail::assertSent(PasswordResetCodeMail::class, 1);
    }

    public function test_nothing_is_sent_for_a_disabled_account_or_a_student_without_a_parent_email(): void
    {
        $this->teacher->update(['is_active' => false]);
        $this->request('sara@school.test')->assertOk();

        $this->parent->update(['email' => null]);
        $this->request('ahmad@school.test')->assertOk();

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_codes')->count());
    }

    public function test_the_right_code_sets_the_new_password_and_signs_out_other_devices(): void
    {
        $this->request('sara@school.test');
        $code = $this->sentCode('sara@school.test');
        $this->teacher->createToken('phone');
        $this->teacher->update(['must_change_password' => true]);

        $this->reset('sara@school.test', $code)->assertOk();

        $this->assertTrue(Hash::check('BrandNewPass1', $this->teacher->fresh()->password));
        $this->assertFalse($this->teacher->fresh()->must_change_password);
        $this->assertSame(0, $this->teacher->tokens()->count());
        $this->assertSame(0, DB::table('password_reset_codes')->count());
        $this->postJson('/api/auth/login', ['login' => 'sara@school.test', 'password' => 'BrandNewPass1'])->assertOk();
        $this->reset('sara@school.test', $code)->assertUnprocessable(); // a code works once
    }

    public function test_the_students_new_password_is_set_with_the_code_their_parent_received(): void
    {
        $this->request('ahmad@school.test');
        $code = $this->sentCode('father@mail.test');

        $this->reset('ahmad@school.test', $code)->assertOk();

        $this->postJson('/api/auth/login', ['login' => 'ahmad@school.test', 'password' => 'BrandNewPass1'])->assertOk();
    }

    public function test_a_wrong_code_fails_and_five_wrong_tries_kill_the_code(): void
    {
        $this->request('sara@school.test');
        $code = $this->sentCode('sara@school.test');
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $i) {
            $this->reset('sara@school.test', $wrong)->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->reset('sara@school.test', $code)->assertUnprocessable();
        $this->assertTrue(Hash::check('OldPassword1', $this->teacher->fresh()->password));
    }

    public function test_an_expired_code_and_an_unknown_account_fail_with_the_same_message(): void
    {
        $this->request('sara@school.test');
        $code = $this->sentCode('sara@school.test');
        DB::table('password_reset_codes')->update(['expires_at' => now()->subMinute()]);

        $expired = $this->reset('sara@school.test', $code)->assertUnprocessable()->json('errors.code.0');
        $unknown = $this->reset('nobody@school.test', '123456')->assertUnprocessable()->json('errors.code.0');
        $this->assertSame($expired, $unknown);
    }

    public function test_a_new_request_replaces_the_old_code(): void
    {
        $this->request('sara@school.test');
        $first = $this->sentCode('sara@school.test');
        Mail::fake();
        $this->request('sara@school.test');
        $second = $this->sentCode('sara@school.test');

        $this->assertSame(1, DB::table('password_reset_codes')->count());
        if ($first !== $second) {
            $this->reset('sara@school.test', $first)->assertUnprocessable();
        }
        $this->reset('sara@school.test', $second)->assertOk();
    }

    public function test_the_new_password_must_be_valid(): void
    {
        $this->request('sara@school.test');
        $code = $this->sentCode('sara@school.test');

        $this->reset('sara@school.test', $code, 'short')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->reset('sara@school.test', $code, config('accounts.default_password'))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/reset-password', ['login' => 'sara@school.test', 'code' => $code, 'password' => 'BrandNewPass1', 'password_confirmation' => 'Different1'])->assertUnprocessable();
        $this->assertTrue(Hash::check('OldPassword1', $this->teacher->fresh()->password));
    }

    public function test_requests_are_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->request('sara@school.test')->assertOk();
        }
        $this->request('sara@school.test')->assertStatus(429);
    }
}
