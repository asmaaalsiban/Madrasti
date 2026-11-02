<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Reports\Models\Signature;
use App\Modules\Reports\Services\SignatureImage;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class SignatureTest extends AcademicTestCase
{
    private User $teacher;

    private Student $student;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        app()->setLocale('en');
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->teacher = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara Hassan']);
        $room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id, 'homeroom_teacher_id' => $this->teacher->id]);
        $this->student = Student::create(['student_number' => 'S-1', 'name_ar' => 'أحمد', 'name_en' => 'Ahmad', 'gender' => 'male']);
        Enrollment::create(['student_id' => $this->student->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);
        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->student->guardians()->attach($this->parent->id);
    }

    /**
     * A photo of a signature: blue ink strokes on slightly grey paper with a shadow in one corner.
     *
     * @param  array{0: int, 1: int, 2: int}  $paper
     * @param  array{0: int, 1: int, 2: int}  $ink
     */
    private function photo(array $paper = [238, 236, 230], array $ink = [20, 40, 160], bool $stroke = true, int $w = 600, int $h = 300): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$paper));
        for ($y = 0; $y < $h; $y++) { // a soft shadow growing towards the bottom-right corner
            for ($x = 0; $x < $w; $x++) {
                $shade = (int) (30 * ($x / $w) * ($y / $h));
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $paper[0] - $shade, $paper[1] - $shade, $paper[2] - $shade));
            }
        }
        if ($stroke) {
            imagesetthickness($img, 6);
            $color = imagecolorallocate($img, ...$ink);
            imageline($img, 120, 200, 220, 110, $color);
            imageline($img, 220, 110, 300, 190, $color);
            imageline($img, 300, 190, 460, 100, $color);
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function upload(string $bytes, array $extra = [], string $name = 'signature.png')
    {
        return $this->post('/api/signatures', ['image' => UploadedFile::fake()->createWithContent($name, $bytes), ...$extra], ['Accept' => 'application/json']);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} r, g, b, alpha (0 opaque .. 127 transparent) of a pixel of a PNG */
    private function pixel(string $png, int $x, int $y): array
    {
        $img = imagecreatefromstring($png);
        $c = imagecolorat($img, $x, $y);

        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, ($c >> 24) & 127];
    }

    public function test_the_paper_is_removed_the_ink_keeps_its_colour_and_the_picture_is_cropped(): void
    {
        $png = (new SignatureImage())->process($this->photo());
        [$w, $h] = getimagesizefromstring($png);

        $this->assertLessThan(600, $w); // cropped to the signature
        $this->assertLessThan(300, $h);
        $this->assertSame([127, 127, 127, 127], [$this->pixel($png, 0, 0)[3], $this->pixel($png, $w - 1, 0)[3], $this->pixel($png, 0, $h - 1)[3], $this->pixel($png, $w - 1, $h - 1)[3]]); // every corner transparent, shadow included

        // Somewhere along a stroke the pixel is solid and blue.
        $solid = null;
        for ($y = 0; $y < $h && ! $solid; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $p = $this->pixel($png, $x, $y);
                if ($p[3] === 0) {
                    $solid = $p;
                    break;
                }
            }
        }
        $this->assertNotNull($solid);
        $this->assertGreaterThan($solid[0] + 60, $solid[2]); // blue stays blue
    }

    public function test_a_clean_white_scan_and_a_yellowish_photo_both_work(): void
    {
        foreach ([[255, 255, 255], [225, 215, 170]] as $paper) {
            $png = (new SignatureImage())->process($this->photo($paper, [0, 0, 0]));
            $this->assertSame(127, $this->pixel($png, 1, 1)[3]);
        }
    }

    public function test_a_blank_page_is_refused_with_a_hint(): void
    {
        $this->expectExceptionMessage('No signature was found');
        (new SignatureImage())->process($this->photo(stroke: false));
    }

    public function test_the_strength_decides_how_much_faint_grey_is_taken_away(): void
    {
        $faint = $this->photo([240, 240, 240], [200, 200, 200]); // a pale pencil line
        $gentle = (new SignatureImage())->process($faint, 0);
        $this->assertNotFalse(getimagesizefromstring($gentle)); // gentle: the pale line is kept
        $this->expectExceptionMessage('No signature was found');
        (new SignatureImage())->process($faint, 100); // too strong: even the stroke is taken away
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        $this->expectExceptionMessage('not a readable picture');
        (new SignatureImage())->process('this is not an image');
    }

    public function test_the_admin_adds_a_school_signature_and_it_is_saved_transparent(): void
    {
        $this->actAsAdmin();
        $r = $this->upload($this->photo(), ['scope' => 'school', 'title' => 'Principal', 'signer_name' => 'Mr. Khaled'])->assertCreated();

        $this->assertSame(['school', 'Principal', 'Mr. Khaled', true], [$r->json('data.scope'), $r->json('data.title'), $r->json('data.signer_name'), $r->json('data.is_active')]);
        $this->assertStringStartsWith('data:image/png;base64,', $r->json('data.image'));
        $signature = Signature::first();
        Storage::assertExists([$signature->original_path, $signature->image_path]);
        $png = base64_decode(substr($r->json('data.image'), strlen('data:image/png;base64,')));
        $this->assertSame(127, $this->pixel($png, 0, 0)[3]);

        $list = $this->getJson('/api/signatures')->assertOk()->json('data');
        $this->assertSame(['Principal'], array_column($list['school'], 'title'));
        $this->assertSame([['id' => $this->teacher->id, 'name' => 'Sara Hassan', 'classrooms' => ['5-A']]], $list['homeroom_teachers']);
    }

    public function test_a_school_signature_needs_a_title_and_a_real_picture(): void
    {
        $this->actAsAdmin();
        $this->upload($this->photo(), ['scope' => 'school'])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->upload('not an image', ['scope' => 'school', 'title' => 'Principal'], 'sig.png')->assertUnprocessable();
        $this->upload($this->photo(stroke: false), ['scope' => 'school', 'title' => 'Principal'])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame(0, Signature::count());
        Storage::assertDirectoryEmpty('signatures');
    }

    public function test_a_teacher_has_one_signature_of_their_own_and_replacing_it_removes_the_old_files(): void
    {
        Sanctum::actingAs($this->teacher);
        $first = $this->upload($this->photo(), ['scope' => 'user'])->assertCreated();
        $this->assertSame('Sara Hassan', $first->json('data.signer_name'));
        $old = Signature::first();

        $this->upload($this->photo(ink: [150, 20, 20]), ['scope' => 'user'])->assertCreated();

        $this->assertSame(1, Signature::count());
        Storage::assertMissing([$old->original_path, $old->image_path]);
        Storage::assertExists([Signature::first()->original_path, Signature::first()->image_path]);
        $this->assertCount(1, $this->getJson('/api/signatures')->json('data.personal'));
        $this->assertSame([], $this->getJson('/api/signatures')->json('data.school')); // a teacher sees no school signatures here
    }

    public function test_a_teacher_cannot_add_a_school_signature_or_sign_for_someone_else(): void
    {
        $colleague = User::factory()->staff(StaffType::Teacher)->create();
        Sanctum::actingAs($this->teacher);

        $this->upload($this->photo(), ['scope' => 'school', 'title' => 'Principal'])->assertForbidden();
        $this->upload($this->photo(), ['scope' => 'user', 'user_id' => $colleague->id])->assertCreated(); // the id is ignored: it is their own
        $this->assertSame([$this->teacher->id], Signature::pluck('user_id')->all());
        $this->assertNull(Signature::where('user_id', $colleague->id)->first());
    }

    public function test_the_admin_uploads_a_signature_for_a_teacher(): void
    {
        $this->actAsAdmin();
        $this->upload($this->photo(), ['scope' => 'user', 'user_id' => $this->teacher->id])->assertCreated()->assertJsonPath('data.user.id', $this->teacher->id);
    }

    public function test_parents_and_students_cannot_manage_signatures(): void
    {
        Sanctum::actingAs($this->parent);
        $this->getJson('/api/signatures')->assertForbidden();
        $this->upload($this->photo(), ['scope' => 'user'])->assertForbidden();
    }

    public function test_the_strength_can_be_changed_without_uploading_again(): void
    {
        $this->actAsAdmin();
        $id = $this->upload($this->photo(), ['scope' => 'school', 'title' => 'Principal'])->json('data.id');
        $before = Signature::find($id)->dataUri();

        $this->postJson("/api/signatures/$id/reprocess", ['strength' => 0])->assertOk()->assertJsonPath('data.strength', 0); // gentle keeps the shadow in the corner
        $this->assertNotSame($before, Signature::find($id)->dataUri());
        $this->postJson("/api/signatures/$id/reprocess", ['strength' => 150])->assertUnprocessable();
    }

    public function test_title_name_order_and_visibility_can_be_edited_and_the_owner_only_for_a_personal_one(): void
    {
        $this->actAsAdmin();
        $school = $this->upload($this->photo(), ['scope' => 'school', 'title' => 'Principal'])->json('data.id');
        $this->putJson("/api/signatures/$school", ['title' => 'Vice principal', 'signer_name' => 'Mrs. Huda', 'is_active' => false])->assertOk()->assertJsonPath('data.title', 'Vice principal')->assertJsonPath('data.is_active', false);
        $this->putJson("/api/signatures/$school", ['title' => ''])->assertUnprocessable();

        Sanctum::actingAs($this->teacher);
        $this->putJson("/api/signatures/$school", ['title' => 'Hacked'])->assertForbidden();
        $this->postJson("/api/signatures/$school/reprocess", ['strength' => 10])->assertForbidden();
        $this->deleteJson("/api/signatures/$school")->assertForbidden();
        $mine = $this->upload($this->photo(), ['scope' => 'user'])->json('data.id');
        $this->putJson("/api/signatures/$mine", ['is_active' => false])->assertOk();
        $this->deleteJson("/api/signatures/$mine")->assertNoContent();
        $this->assertNull(Signature::find($mine));
    }

    public function test_deleting_removes_the_files(): void
    {
        $this->actAsAdmin();
        $id = $this->upload($this->photo(), ['scope' => 'school', 'title' => 'Principal'])->json('data.id');
        $s = Signature::find($id);

        $this->deleteJson("/api/signatures/$id")->assertNoContent();
        Storage::assertMissing([$s->original_path, $s->image_path]);
    }

    public function test_the_report_card_carries_the_homeroom_teachers_and_the_schools_signatures(): void
    {
        $this->actAsAdmin();
        $this->upload($this->photo(), ['scope' => 'school', 'title' => 'Principal', 'signer_name' => 'Mr. Khaled']);
        $hidden = $this->upload($this->photo(), ['scope' => 'school', 'title' => 'Counsellor'])->json('data.id');
        $this->putJson("/api/signatures/$hidden", ['is_active' => false]);
        Sanctum::actingAs($this->teacher);
        $this->upload($this->photo(), ['scope' => 'user']);

        Sanctum::actingAs($this->parent);
        $card = $this->getJson("/api/students/{$this->student->id}/report-card")->assertOk()->json('data.signatures');

        $this->assertSame('Sara Hassan', $card['homeroom']['name']);
        $this->assertStringStartsWith('data:image/png;base64,', $card['homeroom']['image']);
        $this->assertSame(['Principal'], array_column($card['school'], 'title')); // the hidden one is left out
        $this->assertSame('Mr. Khaled', $card['school'][0]['name']);
    }

    public function test_a_homeroom_teacher_without_a_signature_still_has_their_name_and_no_image(): void
    {
        Sanctum::actingAs($this->parent);
        $card = $this->getJson("/api/students/{$this->student->id}/report-card")->assertOk()->json('data.signatures');

        $this->assertSame(['name' => 'Sara Hassan', 'image' => null], $card['homeroom']);
        $this->assertSame([], $card['school']);
    }

    public function test_a_section_with_no_homeroom_teacher_has_no_homeroom_signature(): void
    {
        Classroom::query()->update(['homeroom_teacher_id' => null]);
        Sanctum::actingAs($this->parent);

        $this->assertNull($this->getJson("/api/students/{$this->student->id}/report-card")->json('data.signatures.homeroom'));
    }
}
