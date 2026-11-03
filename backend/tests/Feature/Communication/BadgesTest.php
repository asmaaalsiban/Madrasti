<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class BadgesTest extends AcademicTestCase
{
    public function test_the_header_counters_come_in_one_answer_and_agree_with_their_own_endpoints(): void
    {
        $parent = User::factory()->role(Role::Guardian)->create();
        $other = User::factory()->role(Role::Guardian)->create();
        app(Notifier::class)->toUsers([$parent->id, $other->id], 'announcement', ['title' => 'x'], '/parent/announcements');
        app(Notifier::class)->toUsers([$parent->id], 'announcement', ['title' => 'y'], '/parent/announcements');

        Sanctum::actingAs($parent);
        $badges = $this->getJson('/api/badges')->assertOk()->assertJsonPath('data.notifications', 2)->json('data');
        $this->assertSame(['notifications', 'announcements', 'chat', 'surveys'], array_keys($badges));
        $this->assertSame($this->getJson('/api/notifications/unread-count')->json('data.count'), $badges['notifications']);
        $this->assertSame($this->getJson('/api/announcements/unread-count')->json('data.count'), $badges['announcements']);
        $this->assertSame($this->getJson('/api/chat/unread-count')->json('data.count'), $badges['chat']);
        $this->assertSame($this->getJson('/api/my-surveys/pending')->json('data.count'), $badges['surveys']);

        Sanctum::actingAs($other);
        $this->getJson('/api/badges')->assertJsonPath('data.notifications', 1); // everybody sees only their own
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/badges')->assertUnauthorized();
    }
}
