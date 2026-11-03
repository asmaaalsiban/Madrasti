<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Do the parents allow the school to publish photos of the child (the class feed)? null = nobody answered yet.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('students', 'photo_consent')) {
            Schema::table('students', function (Blueprint $t): void {
                $t->boolean('photo_consent')->nullable();
                $t->timestamp('photo_consent_at')->nullable();
                $t->foreignId('photo_consent_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('students', 'photo_consent')) {
            Schema::table('students', function (Blueprint $t): void {
                $t->dropForeign(['photo_consent_by']);
                $t->dropColumn(['photo_consent', 'photo_consent_at', 'photo_consent_by']);
            });
        }
    }
};
