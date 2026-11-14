<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Services\Avatar;
use App\Shared\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Tests\TenantTestCase;

/** A person's picture: an uploaded photo (not for students) or a ready-made character. */
class AvatarTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([InitializeTenancyByRequestData::class]);
        Storage::fake();
    }

    private function as(Role $role): User
    {
        $user = User::factory()->role($role)->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /** A square JPEG, like the one the browser cuts (see browser-crop.jpg: a real 512x512 file produced by PhotoCropper in Chromium). */
    private function photo(?int $side = null): UploadedFile
    {
        if ($side === null) {
            return new UploadedFile(__DIR__.'/browser-crop.jpg', 'avatar.jpg', 'image/jpeg', null, true);
        }

        return UploadedFile::fake()->image('avatar.jpg', $side, $side);
    }

    public function test_a_teacher_uploads_the_photo_the_browser_cut(): void
    {
        $teacher = $this->as(Role::Teacher);

        $r = $this->post('/api/profile/avatar', ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertOk();

        $r->assertJsonPath('data.avatar.type', 'photo')->assertJsonPath('data.avatar.url', "/users/{$teacher->id}/avatar");
        $path = $teacher->fresh()->avatar_path;
        Storage::assertExists($path);
        [$w, $h, $type] = getimagesizefromstring(Storage::get($path));
        $this->assertSame([512, 512, IMAGETYPE_JPEG], [$w, $h, $type]);
    }

    public function test_a_new_photo_replaces_the_old_one_and_removes_its_file(): void
    {
        $teacher = $this->as(Role::Teacher);
        $this->post('/api/profile/avatar', ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $first = $teacher->fresh()->avatar_path;

        $this->post('/api/profile/avatar', ['photo' => $this->photo(300)], ['Accept' => 'application/json'])->assertOk();

        Storage::assertMissing($first);
        Storage::assertExists($teacher->fresh()->avatar_path);
        $this->assertCount(1, Storage::files('avatars'));
    }

    public function test_the_photo_must_be_a_real_picture_of_a_sensible_size(): void
    {
        $this->as(Role::Teacher);

        $this->post('/api/profile/avatar', ['photo' => UploadedFile::fake()->create('x.txt', 5, 'text/plain')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/profile/avatar', ['photo' => $this->photo(32)], ['Accept' => 'application/json'])->assertStatus(422); // too small
        $this->post('/api/profile/avatar', ['photo' => $this->photo(2000)], ['Accept' => 'application/json'])->assertStatus(422); // too big
        $this->post('/api/profile/avatar', ['photo' => UploadedFile::fake()->image('wide.jpg', 400, 300)], ['Accept' => 'application/json'])->assertStatus(422); // not square
        $this->post('/api/profile/avatar', ['photo' => UploadedFile::fake()->image('a.png', 400, 400)], ['Accept' => 'application/json'])->assertStatus(422); // not a JPEG
        $this->post('/api/profile/avatar', ['photo' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')], ['Accept' => 'application/json'])->assertStatus(422); // named .jpg, is not a picture
        $this->assertCount(0, Storage::files('avatars'));
    }

    public function test_a_student_cannot_upload_a_photo_but_chooses_a_character(): void
    {
        $student = $this->as(Role::Student);

        $this->post('/api/profile/avatar', ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertCount(0, Storage::files('avatars'));

        $this->putJson('/api/profile/avatar/preset', ['key' => 'a07'])->assertOk()
            ->assertJsonPath('data.avatar', ['type' => 'preset', 'key' => 'a07']);
        $this->assertSame('preset:a07', $student->fresh()->avatar_path);
    }

    public function test_a_character_must_be_one_of_the_ready_made_ones(): void
    {
        $this->as(Role::Guardian);

        foreach (['a00', 'a25', 'a1', 'b07', '../a07', 'a07.svg', ''] as $bad) {
            $this->putJson('/api/profile/avatar/preset', ['key' => $bad])->assertStatus(422);
        }
        foreach (['a01', 'a09', 'a10', 'a24'] as $good) {
            $this->putJson('/api/profile/avatar/preset', ['key' => $good])->assertOk();
        }
    }

    public function test_choosing_a_character_replaces_a_photo_and_removes_its_file(): void
    {
        $teacher = $this->as(Role::Teacher);
        $this->post('/api/profile/avatar', ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $file = $teacher->fresh()->avatar_path;

        $this->putJson('/api/profile/avatar/preset', ['key' => 'a03'])->assertOk();

        Storage::assertMissing($file);
        $this->assertSame('preset:a03', $teacher->fresh()->avatar_path);
    }

    public function test_removing_the_picture_goes_back_to_the_initials(): void
    {
        $teacher = $this->as(Role::Teacher);
        $this->post('/api/profile/avatar', ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $file = $teacher->fresh()->avatar_path;

        $this->deleteJson('/api/profile/avatar')->assertOk()->assertJsonPath('data.avatar', null);

        Storage::assertMissing($file);
        $this->assertNull($teacher->fresh()->avatar_path);
    }

    public function test_anybody_signed_in_can_see_a_photo_but_a_character_or_nothing_has_no_photo(): void
    {
        $teacher = $this->as(Role::Teacher);
        $this->post('/api/profile/avatar', ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $student = User::factory()->role(Role::Student)->create(['avatar_path' => 'preset:a05']);
        $plain = User::factory()->role(Role::Teacher)->create();

        $this->as(Role::Guardian);
        $this->get("/api/users/{$teacher->id}/avatar")->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get("/api/users/{$student->id}/avatar")->assertNotFound();
        $this->get("/api/users/{$plain->id}/avatar")->assertNotFound();
    }

    public function test_the_photo_needs_a_sign_in(): void
    {
        $teacher = User::factory()->role(Role::Teacher)->create(['avatar_path' => 'avatars/x.jpg']);

        $this->getJson("/api/users/{$teacher->id}/avatar")->assertUnauthorized();
    }

    public function test_the_signed_in_person_gets_their_picture_with_their_profile(): void
    {
        $user = $this->as(Role::Teacher);
        $user->update(['avatar_path' => 'preset:a12']);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.avatar', ['type' => 'preset', 'key' => 'a12'])->assertJsonMissingPath('data.avatar_path');
    }

    public function test_the_picture_of_a_person_without_one_is_null(): void
    {
        $this->assertNull(Avatar::for(User::factory()->role(Role::Teacher)->create()));
    }
}
