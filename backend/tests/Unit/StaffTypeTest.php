<?php

namespace Tests\Unit;

use App\Shared\Enums\StaffType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StaffTypeTest extends TestCase
{
    public function test_every_type_belongs_to_exactly_one_known_category(): void
    {
        $categories = StaffType::categories();

        $this->assertSame(['teaching', 'administrative', 'student_affairs', 'health', 'technical', 'services'], $categories);
        foreach (StaffType::cases() as $type) {
            $this->assertContains($type->category(), $categories, $type->value);
        }
        $this->assertCount(count(StaffType::cases()), array_unique(array_map(fn ($t) => $t->value, StaffType::cases())));
    }

    public function test_the_school_staff_the_user_asked_for_exist(): void
    {
        $values = array_map(fn ($t) => $t->value, StaffType::cases());

        foreach (['teacher', 'head_of_department', 'trainee', 'floor_supervisor', 'pharmacist', 'nurse', 'caretaker', 'principal', 'general_manager', 'accountant', 'registrar'] as $expected) {
            $this->assertContains($expected, $values);
        }
    }

    /**
     * @return array<string, array{0: StaffType, 1: bool, 2: bool, 3: bool, 4: bool}>
     */
    public static function rules(): array
    {
        return [
            'teacher' => [StaffType::Teacher, true, false, false, true],
            'head of department' => [StaffType::HeadOfDepartment, true, false, false, true],
            'trainee' => [StaffType::Trainee, true, false, false, false],
            'floor supervisor' => [StaffType::FloorSupervisor, false, true, false, false],
            'principal' => [StaffType::Principal, false, false, true, false],
            'general manager' => [StaffType::GeneralManager, false, false, true, false],
            'accountant' => [StaffType::Accountant, false, false, false, false],
            'nurse' => [StaffType::Nurse, false, false, false, false],
        ];
    }

    #[DataProvider('rules')]
    public function test_what_each_type_needs_and_may_do(StaffType $type, bool $subject, bool $floor, bool $admin, bool $homeroom): void
    {
        $this->assertSame($subject, $type->needsSubject());
        $this->assertSame($floor, $type->needsFloor());
        $this->assertSame($admin, $type->grantsAdminAccess());
        $this->assertSame($homeroom, $type->canBeHomeroom());
    }

    public function test_helpers_for_filters(): void
    {
        $this->assertContains('teacher', StaffType::valuesIn('teaching'));
        $this->assertNotContains('nurse', StaffType::valuesIn('teaching'));
        $this->assertSame(['teacher', 'head_of_department'], StaffType::homeroomValues());
        $this->assertSame([], StaffType::valuesIn('nope'));
    }
}
