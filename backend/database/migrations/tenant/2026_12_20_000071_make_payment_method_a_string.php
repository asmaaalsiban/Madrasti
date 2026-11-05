<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** payments.method was a fixed list; the year-end "carried forward" entry is one more value, so it becomes plain text (the app validates the list). */
    public function up(): void
    {
        // Running it again only turns text into text (a half-run migration is safe to run twice).
        Schema::table('payments', function (Blueprint $t): void {
            $t->string('method', 20)->change();
        });
    }

    public function down(): void
    {
        // Kept as text: turning it back into a fixed list would fail once a "carried forward" entry exists.
    }
};
