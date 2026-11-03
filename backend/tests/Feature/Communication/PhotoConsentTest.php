<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class PhotoConsentTest extends AcademicTestCase
{
    private User $admin;

    private User $dad;

    private User $mum;

    private Student $ali;

    private Student $sami;

    private Classroom $room;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->room = Classroom::factory()->create(['name' => '5-A', 'grade_level_id' => GradeLevel::factory()->create()->id, 'academic_year_id' => $year->id]);
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->dad = User::factory()->role(Role::Guardian)->create();
        $this->mum = User::factory()->role(Role::Guardian)->create();
        foreach (['ali' => $this->dad, 'sami' => $this->mum] as $name => $parent) {
            $s = Student::create(['student_number' => "S-$name", 'name_ar' => $name, 'name_en' => ucfirst($name)]);
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id]);
            $s->guardians()->attach($parent->id);
            $this->{$name} = $s;
        }
    }

    private function post_photo()
    {
        Sanctum::actingAs($this->admin);

        return $this->post('/api/stories', ['audience' => 'classroom', 'classroom_id' => $this->room->id, 'body' => 'Trip', 'images' => [UploadedFile::fake()->image('a.jpg', 600, 400)->size(300)]], ['Accept' => 'application/json']);
    }

    public function test_a_parent_allows_or_refuses_photos_of_their_own_child_only(): void
    {
        Sanctum::actingAs($this->dad);
        $this->putJson('/api/privacy/photo-consent', ['student_id' => $this->ali->id, 'allowed' => true])->assertOk();
        $this->assertTrue($this->ali->refresh()->photo_consent);
        $this->putJson('/api/privacy/photo-consent', ['student_id' => $this->sami->id, 'allowed' => true])->assertForbidden(); // not their child
        $this->getJson('/api/privacy/requests')->assertJsonPath('children.0.photo_consent', true);
        $this->putJson('/api/privacy/photo-consent', ['student_id' => $this->ali->id, 'allowed' => false])->assertOk(); // can be withdrawn
        $this->assertFalse($this->ali->refresh()->photo_consent);
    }

    public function test_in_required_mode_photos_only_go_to_a_group_where_every_childs_parents_said_yes(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/privacy/photo-consent-mode', ['mode' => 'required'])->assertOk();
        $this->post_photo()->assertStatus(422)->assertJsonValidationErrors('images');
        $this->getJson("/api/stories/consent-check?audience=classroom&classroom_id={$this->room->id}")->assertOk()->assertJsonPath('mode', 'required')->assertJsonPath('missing_count', 2);

        $this->ali->forceFill(['photo_consent' => true])->save();
        $this->sami->forceFill(['photo_consent' => false])->save(); // a "no" blocks as well
        $this->post_photo()->assertStatus(422);
        $this->getJson("/api/stories/consent-check?audience=classroom&classroom_id={$this->room->id}")->assertJsonPath('missing_count', 1)->assertJsonPath('missing.0.name_en', 'Sami');

        $this->putJson('/api/privacy/photo-consent', ['student_id' => $this->sami->id, 'allowed' => true])->assertOk(); // the principal records a paper form
        $this->post_photo()->assertCreated();
        // A post without photos is never blocked.
        $this->post('/api/stories', ['audience' => 'classroom', 'classroom_id' => $this->room->id, 'body' => 'Just words'], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_in_the_default_warn_mode_photos_are_allowed_but_the_teacher_is_told_who_has_not_said_yes_and_the_mode_is_the_principals(): void
    {
        $this->post_photo()->assertCreated();
        $this->getJson("/api/stories/consent-check?audience=classroom&classroom_id={$this->room->id}")->assertJsonPath('mode', 'warn')->assertJsonPath('missing_count', 2);
        $this->putJson('/api/privacy/photo-consent-mode', ['mode' => 'off'])->assertOk();
        $this->post_photo()->assertCreated();
        $this->putJson('/api/privacy/photo-consent-mode', ['mode' => 'sometimes'])->assertStatus(422);
        $this->getJson('/api/privacy/photo-consent?classroom_id='.$this->room->id)->assertOk()->assertJsonPath('mode', 'off')->assertJsonCount(2, 'data')->assertJsonPath('missing', 2);

        Sanctum::actingAs($this->dad);
        $this->putJson('/api/privacy/photo-consent-mode', ['mode' => 'off'])->assertForbidden();
        $this->getJson('/api/privacy/photo-consent')->assertForbidden();
    }
}
