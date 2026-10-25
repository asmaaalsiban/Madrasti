<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Http\Controllers\FinanceController as Finance;
use App\Modules\Finance\Services\AccountReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** A parent reads the statement of their child: the fees, installments, what was paid and what is still owed. */
class StudentFeesController extends Controller
{
    public function __invoke(Student $student, AccountReader $reader, Finance $finance): JsonResponse
    {
        Gate::authorize('view-student-fees', $student);

        return response()->json(['data' => $reader->statement($student, AcademicYear::where('is_current', true)->first()) + ['currency' => $finance->currency()]]);
    }
}
