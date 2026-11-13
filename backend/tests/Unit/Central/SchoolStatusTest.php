<?php

namespace Tests\Unit\Central;

use App\Central\Models\Tenant;
use App\Central\Support\SchoolState;
use App\Central\Support\SchoolStatus;
use Carbon\Carbon;
use Tests\TestCase;

class SchoolStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function school(array $attributes = []): Tenant
    {
        return new Tenant(array_merge(['id' => 'demo', 'name' => 'Demo', 'is_active' => true], $attributes));
    }

    public function test_active_without_an_end_date(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');

        $this->assertSame(SchoolState::Active, SchoolStatus::of($this->school()));
    }

    public function test_a_school_ending_today_is_active_until_the_end_of_the_day_and_expired_after(): void
    {
        $school = $this->school(['subscription_ends_at' => '2026-11-12']);

        $this->assertSame(SchoolState::Active, SchoolStatus::of($school, Carbon::parse('2026-11-12 23:59:59')));
        $this->assertSame(SchoolState::Expired, SchoolStatus::of($school, Carbon::parse('2026-11-13 00:00:00')));
    }

    public function test_inactive_is_suspended(): void
    {
        $school = $this->school(['is_active' => false]);

        $this->assertSame(SchoolState::Suspended, SchoolStatus::of($school, Carbon::parse('2026-11-12 10:00:00')));
    }

    public function test_an_exit_window_in_the_future_wins_over_suspended_and_expired(): void
    {
        $school = $this->school([
            'is_active' => false,
            'subscription_ends_at' => '2026-01-01',
            'exit_window_ends_at' => '2026-11-20 00:00:00',
        ]);

        $this->assertSame(SchoolState::ExitWindow, SchoolStatus::of($school, Carbon::parse('2026-11-12 10:00:00')));
    }

    public function test_a_past_exit_window_or_closed_at_is_closed(): void
    {
        $now = Carbon::parse('2026-11-12 10:00:00');

        $pastWindow = $this->school(['exit_window_ends_at' => '2026-11-01 00:00:00']);
        $closed = $this->school(['closed_at' => '2026-11-05 00:00:00']);

        $this->assertSame(SchoolState::Closed, SchoolStatus::of($pastWindow, $now));
        $this->assertSame(SchoolState::Closed, SchoolStatus::of($closed, $now));
    }

    public function test_blocked_is_true_for_all_but_active(): void
    {
        $now = Carbon::parse('2026-11-12 10:00:00');

        $this->assertFalse(SchoolStatus::blocked($this->school(), $now));
        $this->assertTrue(SchoolStatus::blocked($this->school(['is_active' => false]), $now));
        $this->assertTrue(SchoolStatus::blocked($this->school(['subscription_ends_at' => '2026-11-01']), $now));
        $this->assertTrue(SchoolStatus::blocked($this->school(['exit_window_ends_at' => '2026-11-20 00:00:00']), $now));
        $this->assertTrue(SchoolStatus::blocked($this->school(['closed_at' => '2026-11-05 00:00:00']), $now));
    }

    public function test_the_current_school_is_not_blocked_when_no_tenant_is_initialised(): void
    {
        $this->assertFalse(SchoolStatus::currentSchoolBlocked());
    }
}
