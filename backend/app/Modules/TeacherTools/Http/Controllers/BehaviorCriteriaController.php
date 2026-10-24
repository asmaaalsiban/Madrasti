<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\TeacherTools\Models\BehaviorCriterion;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** What students are evaluated on. Everyone who evaluates reads the active ones; the admin adds, renames, hides and removes. */
class BehaviorCriteriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $rows = BehaviorCriterion::when(! $user->hasRole(Role::Admin), fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $criterion = BehaviorCriterion::create($data + ['sort_order' => (int) BehaviorCriterion::max('sort_order') + 1]);

        return response()->json(['data' => $criterion], 201);
    }

    public function update(Request $request, BehaviorCriterion $criterion): JsonResponse
    {
        $criterion->update($this->validated($request));

        return response()->json(['data' => $criterion]);
    }

    /** A criterion that was already used keeps its history: it is hidden instead of removed. */
    public function destroy(BehaviorCriterion $criterion): Response
    {
        if (DB::table('behavior_evaluation_scores')->where('behavior_criterion_id', $criterion->id)->exists()) {
            $criterion->update(['is_active' => false]);
        } else {
            $criterion->delete();
        }

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
