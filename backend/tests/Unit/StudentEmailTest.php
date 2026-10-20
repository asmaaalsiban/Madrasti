<?php

namespace Tests\Unit;

use App\Models\User;
use App\Modules\People\Services\StudentEmail;
use App\Shared\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TenantTestCase;

class StudentEmailTest extends TenantTestCase
{
    private function generate(string $number, ?string $nameEn, string $school = 'school2'): string
    {
        return app(StudentEmail::class)->generate($number, $nameEn, $school);
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function cases(): array
    {
        return [
            'english name and code' => ['D-1001', 'Ahmad Khaled', 'ahmad.d1001@school2.edu'],
            'only the first name is used' => ['D-1001', 'Ahmad Khaled Ali', 'ahmad.d1001@school2.edu'],
            'no english name' => ['D-1001', null, 'd1001@school2.edu'],
            'blank english name' => ['D-1001', '   ', 'd1001@school2.edu'],
            'arabic typed in the english field' => ['D-1001', 'أحمد خالد', 'd1001@school2.edu'],
            'accents and punctuation' => ['S 22/7', "José O'Brien", 'jose.s227@school2.edu'],
            'symbols only in the code' => ['---', 'Ahmad', 'ahmad.student@school2.edu'],
            'upper case becomes lower case' => ['ABC123', 'SARA', 'sara.abc123@school2.edu'],
        ];
    }

    #[DataProvider('cases')]
    public function test_builds_the_email_from_the_name_the_code_and_the_school(string $number, ?string $nameEn, string $expected): void
    {
        $this->assertSame($expected, $this->generate($number, $nameEn));
    }

    public function test_the_domain_can_be_set_in_the_configuration(): void
    {
        config(['accounts.student_email_domain' => 'students.example.org']);

        $this->assertSame('ahmad.d1001@students.example.org', $this->generate('D-1001', 'Ahmad'));
    }

    public function test_the_school_code_is_the_default_domain(): void
    {
        config(['accounts.student_email_domain' => null]);

        $this->assertSame('ahmad.d1001@alnoor.edu', $this->generate('D-1001', 'Ahmad', 'alnoor'));
    }

    public function test_a_taken_email_gets_a_number_even_when_the_old_account_was_deleted(): void
    {
        User::factory()->role(Role::Teacher)->create(['email' => 'ahmad.d1001@school2.edu']);
        $gone = User::factory()->role(Role::Student)->create(['email' => 'ahmad.d1001-2@school2.edu']);
        $gone->delete();

        $this->assertSame('ahmad.d1001-3@school2.edu', $this->generate('D-1001', 'Ahmad'));
    }
}
