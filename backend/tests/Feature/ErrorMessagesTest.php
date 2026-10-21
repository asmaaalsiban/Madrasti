<?php

namespace Tests\Feature;

use Tests\Feature\Academic\AcademicTestCase;

class ErrorMessagesTest extends AcademicTestCase
{
    public function test_framework_error_messages_follow_the_request_language(): void
    {
        $arabic = $this->withHeader('Accept-Language', 'ar')->getJson('/api/attendance/classrooms');
        $english = $this->withHeader('Accept-Language', 'en')->getJson('/api/attendance/classrooms');

        $arabic->assertUnauthorized();
        $this->assertSame('يرجى تسجيل الدخول.', $arabic->json('message'));
        $this->assertSame('Unauthenticated.', $english->json('message'));
    }
}
