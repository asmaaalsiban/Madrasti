<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MailFromTest extends TestCase
{
    /** An empty `MAIL_FROM_ADDRESS=` line in .env must not leave mail without a sender. */
    public function test_an_empty_from_address_falls_back_to_a_sender(): void
    {
        foreach (['MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'APP_NAME'] as $key) {
            putenv("$key=");
            $_ENV[$key] = $_SERVER[$key] = '';
        }
        // The config file calls the global env() helper; load it with a small stand-in.
        if (! function_exists('env')) {
            eval('function env($key, $default = null) { $v = getenv($key); return $v === false ? $default : $v; }');
        }
        $config = require __DIR__.'/../../config/mail.php';

        $this->assertNotSame('', $config['from']['address']);
        $this->assertNotSame('', $config['from']['name']);
    }
}
