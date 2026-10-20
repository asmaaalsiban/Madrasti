<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * What a member of the school staff is. The account role stays coarse (admin / teacher): management
 * positions get the admin role, everyone else the teacher role. Fine-grained permissions per type come
 * with the screens of each module.
 */
enum StaffType: string
{
    // Teaching
    case Teacher = 'teacher';
    case HeadOfDepartment = 'head_of_department';
    case SubjectCoordinator = 'subject_coordinator';
    case Trainee = 'trainee';
    case SubstituteTeacher = 'substitute_teacher';

    // Administrative
    case Principal = 'principal';
    case VicePrincipal = 'vice_principal';
    case GeneralManager = 'general_manager';
    case Registrar = 'registrar';
    case Accountant = 'accountant';
    case Hr = 'hr';
    case DataEntry = 'data_entry';

    // Student affairs
    case Counselor = 'counselor';
    case FloorSupervisor = 'floor_supervisor';
    case BusSupervisor = 'bus_supervisor';
    case ActivitiesCoordinator = 'activities_coordinator';
    case SportsCoach = 'sports_coach';

    // Health
    case Nurse = 'nurse';
    case Pharmacist = 'pharmacist';

    // Technical
    case Librarian = 'librarian';
    case LabTechnician = 'lab_technician';
    case ItSupport = 'it_support';

    // Services
    case Caretaker = 'caretaker';
    case Driver = 'driver';
    case SecurityGuard = 'security_guard';
    case CanteenWorker = 'canteen_worker';

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        return ['teaching', 'administrative', 'student_affairs', 'health', 'technical', 'services'];
    }

    public function category(): string
    {
        return match ($this) {
            self::Teacher, self::HeadOfDepartment, self::SubjectCoordinator, self::Trainee, self::SubstituteTeacher => 'teaching',
            self::Principal, self::VicePrincipal, self::GeneralManager, self::Registrar, self::Accountant, self::Hr, self::DataEntry => 'administrative',
            self::Counselor, self::FloorSupervisor, self::BusSupervisor, self::ActivitiesCoordinator, self::SportsCoach => 'student_affairs',
            self::Nurse, self::Pharmacist => 'health',
            self::Librarian, self::LabTechnician, self::ItSupport => 'technical',
            self::Caretaker, self::Driver, self::SecurityGuard, self::CanteenWorker => 'services',
        };
    }

    /** Teaching types are linked to the subject they teach. */
    public function needsSubject(): bool
    {
        return $this->category() === 'teaching';
    }

    public function needsFloor(): bool
    {
        return $this === self::FloorSupervisor;
    }

    /** Management positions use the school admin screens. */
    public function grantsAdminAccess(): bool
    {
        return in_array($this, [self::Principal, self::VicePrincipal, self::GeneralManager], true);
    }

    public function canBeHomeroom(): bool
    {
        return in_array($this, [self::Teacher, self::HeadOfDepartment], true);
    }

    public function accountRole(): Role
    {
        return $this->grantsAdminAccess() ? Role::Admin : Role::Teacher;
    }

    /**
     * @return list<string>
     */
    public static function valuesIn(string $category): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => $type->category() === $category),
        ));
    }

    /**
     * @return list<string>
     */
    public static function homeroomValues(): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => $type->canBeHomeroom()),
        ));
    }
}
