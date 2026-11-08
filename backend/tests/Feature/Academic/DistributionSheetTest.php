<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;

class DistributionSheetTest extends AcademicTestCase
{
    /** @return list<list<mixed>> the rows of the first sheet of the download */
    private function rows($response): array
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($c) => $c->getValue(), $row->getCells());
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    public function test_the_distribution_table_lists_each_teacher_with_qualifications_subjects_and_total_periods(): void
    {
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $grade = GradeLevel::factory()->create();
        $math = Subject::factory()->create(['name_en' => 'Math']);
        $art = Subject::factory()->create(['name_en' => 'Art']);
        $omar = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id, 'degree' => 'master', 'specialization' => 'Curriculum', 'university' => 'Jerash', 'graduation_year' => 2019, 'experience_years' => 15, 'courses' => 'ICDL'])->create(['name' => 'Omar']);
        User::factory()->staff(StaffType::Principal)->create(['name' => 'Rana']);
        $a = Classroom::factory()->create(['name' => '7-A', 'grade_level_id' => $grade->id, 'academic_year_id' => $year->id]);
        $b = Classroom::factory()->create(['name' => '7-B', 'grade_level_id' => $grade->id, 'academic_year_id' => $year->id]);
        TeachingAssignment::create(['classroom_id' => $a->id, 'subject_id' => $math->id, 'teacher_id' => $omar->id, 'weekly_periods' => 5]);
        TeachingAssignment::create(['classroom_id' => $b->id, 'subject_id' => $math->id, 'teacher_id' => $omar->id, 'weekly_periods' => 5]);
        TeachingAssignment::create(['classroom_id' => $b->id, 'subject_id' => $art->id, 'teacher_id' => $omar->id, 'weekly_periods' => 1]);
        $this->actAsAdmin();

        $response = $this->get('/api/teaching-assignments/export')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $flat = array_merge(...$this->rows($response));
        foreach (['Omar', 'Curriculum', 'Jerash', 'ICDL', '7-A', '7-B', 'Math', 'Art', 'Rana'] as $expected) {
            $this->assertContains($expected, $flat, $expected);
        }
        $this->assertContains(11, $flat); // 5 + 5 + 1 periods a week
        $this->assertContains('Principal', $flat); // a non-teaching member of staff shows the job in the notes
    }

    public function test_only_the_admin_gets_the_distribution_table(): void
    {
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->get('/api/teaching-assignments/export')->assertForbidden();
    }
}
