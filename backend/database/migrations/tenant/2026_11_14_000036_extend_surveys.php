<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $t) {
            $t->string('audience', 16)->default('all'); // all | classroom: which parents get it
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('closed_at')->nullable(); // closed by hand before closes_at
            $t->timestamp('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $t) {
            $t->dropConstrainedForeignId('classroom_id');
            $t->dropColumn(['audience', 'closed_at', 'reminded_at']);
        });
    }
};
