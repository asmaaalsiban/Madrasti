<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class PushKeysTest extends TestCase
{
    public function test_the_command_prints_the_three_env_lines(): void
    {
        $this->artisan('push:generate-keys')
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->expectsOutputToContain('VAPID_SUBJECT=')
            ->assertSuccessful();
    }
}
