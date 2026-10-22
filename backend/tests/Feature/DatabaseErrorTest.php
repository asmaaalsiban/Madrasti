<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DatabaseErrorTest extends TestCase
{
    private function boom(string $message): void
    {
        Route::middleware('api')->get('/_boom', fn () => throw new QueryException('mysql', 'select * from tenants', [], new \PDOException($message)));
    }

    public function test_a_database_that_is_down_is_explained_without_showing_the_sql(): void
    {
        $this->boom('SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it');

        $arabic = $this->withHeader('Accept-Language', 'ar')->getJson('/api/_boom')->assertStatus(503);
        $english = $this->withHeader('Accept-Language', 'en')->getJson('/api/_boom')->assertStatus(503);

        $this->assertSame(__('errors.database_down', [], 'ar'), $arabic->json('message'));
        $this->assertStringContainsString('MySQL', $english->json('message'));
        $this->assertStringNotContainsString('SQLSTATE', $english->getContent());
        $this->assertStringNotContainsString('select', $english->getContent());
    }

    public function test_any_other_database_error_is_a_plain_message_too(): void
    {
        $this->boom('SQLSTATE[42S02]: Base table or view not found');

        $response = $this->withHeader('Accept-Language', 'en')->getJson('/api/_boom')->assertStatus(500);

        $this->assertSame(__('errors.database_error', [], 'en'), $response->json('message'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }
}
