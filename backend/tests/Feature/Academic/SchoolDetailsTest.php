<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;

class SchoolDetailsTest extends AcademicTestCase
{
    /** @return list<mixed> every cell of the first sheet */
    private function cells($response): array
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($response->baseResponse->getFile()->getPathname());
        $cells = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCells() as $c) {
                    $cells[] = $c->getValue();
                }
            }
            break;
        }
        $reader->close();

        return $cells;
    }

    public function test_the_directorate_and_area_are_saved_and_printed_on_both_forms(): void
    {
        AcademicYear::factory()->create(['is_current' => true]);
        $this->actAsAdmin();
        $this->getJson('/api/school-details')->assertOk()->assertJsonPath('data.directorate', '');

        $this->putJson('/api/school-details', ['directorate' => 'Ain Al-Basha', 'district' => 'Umm Al-Dananeer'])->assertOk()->assertJsonPath('data.district', 'Umm Al-Dananeer');
        foreach (['/api/timetable/export', '/api/teaching-assignments/export'] as $url) {
            $text = implode(' | ', array_filter($this->cells($this->get($url)->assertOk()), 'is_string'));
            $this->assertStringContainsString('Ain Al-Basha', $text, $url);
            $this->assertStringContainsString('Umm Al-Dananeer', $text, $url);
        }
    }

    public function test_without_details_the_forms_keep_dotted_lines_to_fill_in_by_hand(): void
    {
        AcademicYear::factory()->create(['is_current' => true]);
        $this->actAsAdmin();
        $text = implode(' | ', array_filter($this->cells($this->get('/api/timetable/export')->assertOk()), 'is_string'));
        $this->assertStringContainsString('Directorate of Education: ....................', $text);
    }

    public function test_only_the_admin_can_see_or_change_them(): void
    {
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->getJson('/api/school-details')->assertForbidden();
        $this->putJson('/api/school-details', ['directorate' => 'x'])->assertForbidden();
    }
}
