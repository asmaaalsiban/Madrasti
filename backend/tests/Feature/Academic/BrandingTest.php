<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class BrandingTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    private function logo(string $name = 'logo.png', int $w = 200, int $h = 200): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    public function test_the_principal_sets_the_logo_and_the_colours_and_the_logo_is_public(): void
    {
        $this->actAsAdmin();
        $this->getJson('/api/branding')->assertOk()->assertJsonPath('data.logo_url', null)->assertJsonPath('data.primary_color', null);

        $this->post('/api/branding', ['logo' => $this->logo(), 'primary_color' => '#e0457b', 'dark_color' => '#12285a'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.primary_color', '#e0457b')->assertJsonPath('data.dark_color', '#12285a');
        $url = $this->getJson('/api/branding')->json('data.logo_url');
        $this->assertStringStartsWith('/api/school/logo?v=', $url);

        $this->app['auth']->forgetGuards();
        $this->get('/api/school/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff'); // no sign-in needed
    }

    public function test_the_colours_can_change_without_a_new_logo_and_a_new_logo_replaces_the_old_file(): void
    {
        $this->actAsAdmin();
        $this->post('/api/branding', ['logo' => $this->logo(), 'primary_color' => '#e0457b'], ['Accept' => 'application/json'])->assertOk();
        $first = collect(Storage::allFiles('branding'))->first();

        $this->post('/api/branding', ['primary_color' => '#0a7d5a'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.primary_color', '#0a7d5a');
        $this->assertSame([$first], Storage::allFiles('branding'));

        $this->post('/api/branding', ['logo' => $this->logo('b.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, Storage::allFiles('branding'));
        $this->assertNotSame($first, Storage::allFiles('branding')[0]);
    }

    public function test_back_to_the_default_look(): void
    {
        $this->actAsAdmin();
        $this->post('/api/branding', ['logo' => $this->logo(), 'primary_color' => '#e0457b', 'dark_color' => '#12285a'], ['Accept' => 'application/json'])->assertOk();

        $this->deleteJson('/api/branding')->assertNoContent();

        $this->getJson('/api/branding')->assertJsonPath('data.logo_url', null)->assertJsonPath('data.primary_color', null)->assertJsonPath('data.dark_color', null);
        $this->assertSame([], Storage::allFiles('branding'));
        $this->get('/api/school/logo')->assertNotFound();
    }

    public function test_only_safe_images_and_real_colours_are_accepted(): void
    {
        $this->actAsAdmin();
        $post = fn (array $d) => $this->post('/api/branding', $d, ['Accept' => 'application/json']);

        $post(['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])->assertUnprocessable();
        $post(['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $post(['logo' => UploadedFile::fake()->image('big.png', 200, 200)->size(3000)])->assertUnprocessable();
        $post(['logo' => $this->logo('tiny.png', 10, 10)])->assertUnprocessable();
        $post(['primary_color' => 'pink'])->assertUnprocessable();
        $post(['dark_color' => '#12345'])->assertUnprocessable();
        $this->assertSame([], Storage::allFiles('branding'));
    }

    public function test_only_the_principal_may_change_the_look(): void
    {
        foreach ([User::factory()->staff(StaffType::Teacher)->create(), User::factory()->role(Role::Student)->create(), User::factory()->role(Role::Guardian)->create()] as $user) {
            Sanctum::actingAs($user);
            $this->post('/api/branding', ['primary_color' => '#e0457b'], ['Accept' => 'application/json'])->assertForbidden();
            $this->deleteJson('/api/branding')->assertForbidden();
            $this->getJson('/api/branding')->assertForbidden();
        }
    }
}
