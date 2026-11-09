<?php

declare(strict_types=1);

namespace App\Modules\Discipline\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Discipline\Models\Warning;
use App\Modules\Discipline\Models\WarningRule;
use App\Modules\Discipline\Services\WarningRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The school's optional warning rules (admin). */
class WarningRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => WarningRule::orderBy('subject_type')->orderBy('id')->get(), 'metrics' => WarningRule::METRICS]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => WarningRule::create($this->data($request))], 201);
    }

    public function update(Request $request, WarningRule $rule): JsonResponse
    {
        $rule->update($this->data($request, $rule));

        return response()->json(['data' => $rule->refresh()]);
    }

    public function destroy(WarningRule $rule): Response
    {
        $rule->delete(); // the warnings it raised stay

        return response()->noContent();
    }

    /** Runs all the active rules now (they also run every morning). */
    public function run(WarningRules $rules): JsonResponse
    {
        return response()->json(['data' => $rules->evaluate()]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, ?WarningRule $rule = null): array
    {
        $d = $request->validate([
            'subject_type' => [$rule ? 'sometimes' : 'required', Rule::in(['student', 'staff'])], 'name' => ['required', 'string', 'max:120'],
            'metric' => ['required', Rule::in(['absent_days', 'late_count', 'negative_notes'])], 'threshold' => ['required', 'integer', 'between:1,500'], 'window_days' => ['required', 'integer', 'between:1,366'],
            'category' => ['required', Rule::in(Warning::CATEGORIES)], 'level' => ['required', Rule::in(Warning::LEVELS)], 'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:4000'], 'auto_send' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $subject = $d['subject_type'] ?? $rule->subject_type;
        if (! in_array($d['metric'], WarningRule::METRICS[$subject], true)) {
            throw ValidationException::withMessages(['metric' => [__('discipline.metric_not_for_staff')]]);
        }

        return $d;
    }
}
