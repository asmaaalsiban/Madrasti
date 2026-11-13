<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\Term;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Grading\Models\Assessment;
use App\Modules\Grading\Models\AssessmentScore;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminDashboardTest extends AcademicTestCase
{
    public function test_empty_school_returns_zeros(): void
    {
        $this->actAsAdmin();
        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');

        $this->assertSame(0, $d['people']['students']);
        $this->assertSame(['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0], $d['students_today']);
        $this->assertSame(0.0, (float) $d['finance']['billed']);
        $this->assertCount(6, $d['finance']['monthly']);
        $this->assertSame([], $d['attendance_trend']);
        $this->assertSame(['procurement' => 0, 'privacy' => 0], $d['waiting']);
    }

    public function test_counts_today_trend_and_frequent_absences(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $student = Student::create(['student_number' => 'D-1', 'name_ar' => 'أ', 'name_en' => 'A', 'gender' => 'male']);
        $other = Student::create(['student_number' => 'D-2', 'name_ar' => 'ب', 'name_en' => 'B', 'gender' => 'male']);
        foreach ([$student, $other] as $s) {
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $room->id, 'academic_year_id' => $room->academic_year_id]);
        }
        $classroom = $room->id;
        foreach ([0, 1, 2] as $ago) {
            StudentAttendance::create(['student_id' => $student->id, 'classroom_id' => $classroom, 'date' => now()->subDays($ago)->toDateString(), 'status' => 'absent', 'marked_by' => auth()->id()]);
        }
        StudentAttendance::create(['student_id' => $other->id, 'classroom_id' => $classroom, 'date' => now()->toDateString(), 'status' => 'present', 'marked_by' => auth()->id()]);

        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');

        $this->assertSame(2, $d['people']['students']);
        $this->assertSame(1, $d['students_today']['absent']);
        $this->assertSame(1, $d['students_today']['present']);
        $this->assertCount(3, $d['attendance_trend']);
        $this->assertSame($student->id, $d['frequent_absences'][0]['id']);
        $this->assertSame(3, $d['frequent_absences'][0]['days']);
    }

    public function test_money_and_marks_are_added_up_correctly(): void
    {
        $admin = $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $student = Student::create(['student_number' => 'D-1', 'name_ar' => 'أ', 'name_en' => 'A', 'gender' => 'male']);
        Enrollment::create(['student_id' => $student->id, 'classroom_id' => $room->id, 'academic_year_id' => $room->academic_year_id]);

        $feeA = StudentFee::create(['student_id' => $student->id, 'academic_year_id' => $room->academic_year_id, 'kind' => 'tuition', 'description' => 'Tuition', 'total_amount' => '1000.00', 'discount' => '100.00']);
        StudentFee::create(['student_id' => $student->id, 'academic_year_id' => $room->academic_year_id, 'kind' => 'transport', 'description' => 'Bus', 'total_amount' => '500.00', 'discount' => '0']);
        Installment::create(['student_fee_id' => $feeA->id, 'number' => 1, 'due_date' => now()->subDays(10)->toDateString(), 'amount' => '400.00', 'paid_amount' => '150.00', 'status' => 'partial']);
        Installment::create(['student_fee_id' => $feeA->id, 'number' => 2, 'due_date' => now()->subDays(5)->toDateString(), 'amount' => '100.00', 'paid_amount' => '100.00', 'status' => 'paid']);
        Installment::create(['student_fee_id' => $feeA->id, 'number' => 3, 'due_date' => now()->addDays(20)->toDateString(), 'amount' => '300.00', 'paid_amount' => '0', 'status' => 'pending']);
        $pay = fn (string $amount, string $on, string $method = 'cash') => Payment::create(['student_fee_id' => $feeA->id, 'amount' => $amount, 'method' => $method, 'paid_on' => $on, 'received_by' => $admin->id]);
        $pay('300.00', now()->toDateString());
        $pay('200.00', now()->startOfMonth()->subDay()->toDateString()); // last month
        $pay('999.00', now()->toDateString(), Payment::CARRY); // carried forward: not money collected

        $term = Term::create(['academic_year_id' => $room->academic_year_id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31']);
        $math = Subject::factory()->create();
        $art = Subject::factory()->create();
        $exam = fn (Subject $subject, int $max, bool $published) => Assessment::create(['classroom_id' => $room->id, 'subject_id' => $subject->id, 'term_id' => $term->id, 'teacher_id' => $admin->id, 'title' => 'Exam', 'type' => 'exam', 'max_score' => $max, 'weight' => 100, 'is_published' => $published, 'held_on' => '2026-10-05']);
        $mark = fn (Assessment $a, ?string $score, string $status = 'graded') => AssessmentScore::create(['assessment_id' => $a->id, 'student_id' => $student->id, 'score' => $score, 'status' => $status]);
        $mark($exam($math, 20, true), '10');
        $second = $exam($math, 20, true);
        $mark($second, '20');
        $mark($exam($art, 30, true), '15');
        $mark($exam($art, 30, false), '30'); // not published: left out
        $mark($exam($art, 30, true), null, 'absent'); // no score: left out

        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');

        $this->assertEqualsWithDelta(1400.0, $d['finance']['billed'], 0.001);
        $this->assertEqualsWithDelta(500.0, $d['finance']['collected'], 0.001);
        $this->assertEqualsWithDelta(250.0, $d['finance']['overdue'], 0.001);
        $monthly = collect($d['finance']['monthly'])->pluck('amount', 'month');
        $this->assertEqualsWithDelta(300.0, $monthly[now()->format('Y-m')], 0.001);
        $this->assertEqualsWithDelta(200.0, $monthly[now()->startOfMonth()->subDay()->format('Y-m')], 0.001);
        $averages = collect($d['marks_by_subject'])->pluck('average', 'id');
        $this->assertEqualsWithDelta(75.0, $averages[$math->id], 0.001);
        $this->assertEqualsWithDelta(50.0, $averages[$art->id], 0.001);
        $this->assertCount(2, $averages);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_forbidden_for_non_admin(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }
}
