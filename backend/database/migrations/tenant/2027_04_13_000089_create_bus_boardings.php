<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who boarded the bus in a round, ticked by the escort (or the driver). Parents are not told. */
    public function up(): void
    {
        if (! Schema::hasTable('bus_boardings')) {
            Schema::create('bus_boardings', function (Blueprint $t): void {
                $t->id();
                $t->date('date');
                $t->foreignId('bus_id')->constrained('buses')->cascadeOnDelete();
                $t->foreignId('transport_round_id')->constrained('transport_rounds')->cascadeOnDelete();
                $t->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $t->string('status', 12); // boarded | not_boarded
                $t->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['date', 'bus_id', 'transport_round_id', 'student_id'], 'bus_boardings_unique');
                $t->index(['date', 'bus_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_boardings');
    }
};
