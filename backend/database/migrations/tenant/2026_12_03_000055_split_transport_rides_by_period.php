<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A round belongs to the morning (going to school) or the evening (going home), and a student has a round for each way:
// morning round 1 on bus 11, evening round 2 on bus 11. The single round of a ride becomes the round of both ways.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transport_rounds', 'period')) {
            Schema::table('transport_rounds', function (Blueprint $t): void {
                $t->string('period', 10)->default('morning')->after('name_en'); // morning | evening
            });
        }
        if (! Schema::hasColumn('student_transport', 'pickup_round_id')) {
            Schema::table('student_transport', function (Blueprint $t): void {
                $t->foreignId('pickup_round_id')->nullable()->constrained('transport_rounds');
                $t->foreignId('dropoff_round_id')->nullable()->constrained('transport_rounds');
            });
        }
        if (Schema::hasColumn('student_transport', 'transport_round_id')) {
            DB::table('student_transport')->whereNotNull('pickup_bus_id')->update(['pickup_round_id' => DB::raw('transport_round_id')]);
            DB::table('student_transport')->whereNotNull('dropoff_bus_id')->update(['dropoff_round_id' => DB::raw('transport_round_id')]);
            Schema::table('student_transport', function (Blueprint $t): void {
                $t->dropForeign(['transport_round_id']);
            });
            Schema::table('student_transport', function (Blueprint $t): void {
                $t->dropColumn('transport_round_id');
            });
        }
    }

    public function down(): void
    {
        // Not reversible without losing the second round of a student: left as it is.
    }
};
