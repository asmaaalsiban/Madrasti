<?php

declare(strict_types=1);

namespace App\Modules\Grading\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Models\Term;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the signed-in teacher (admin: everybody) may grade this year: classroom + subject pairs, and the terms. */
class GradingOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $year = fn ($q) => $q->where('is_current', true);

        $assignments = TeachingAssignment::query()
            ->whereHas('classroom.academicYear', $year)
            ->when(! $user->hasRole(Role::Admin), fn ($q) => $q->where('teacher_id', $user->id))
            ->with(['classroom', 'subject'])->get()
            ->sortBy(fn ($a) => $a->classroom->name.'|'.$a->subject->name_en)->values();

        return response()->json(['data' => [
            'assignments' => $assignments->map(fn ($a) => [
                'classroom' => $a->classroom->only(['id', 'name']),
                'subject' => $a->subject->only(['id', 'name_ar', 'name_en']),
            ]),
            'terms' => Term::whereHas('academicYear', $year)->orderBy('starts_on')->get()->map->only(['id', 'name']),
        ]]);
    }
}
