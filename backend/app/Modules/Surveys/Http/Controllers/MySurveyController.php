<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Surveys\Models\SurveyRecipient;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Services\SurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A parent's side: the surveys and approvals waiting for them (one per child for an approval), and sending the answer. */
class MySurveyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $rows = SurveyRecipient::with(['survey.questions', 'student:id,name_ar,name_en'])->where('user_id', $user->id)->latest('id')->get();
        $responses = SurveyResponse::with('answers')->where('user_id', $user->id)->get()->keyBy(fn ($r) => $r->survey_id.'-'.($r->student_id ?? 0));

        return response()->json(['data' => $rows->map(function (SurveyRecipient $r) use ($responses): array {
            $s = $r->survey;
            $response = $responses->get($s->id.'-'.($r->student_id ?? 0));

            return [
                'id' => $r->id, 'type' => $s->type, 'title' => $s->title, 'description' => $s->description, 'open' => $s->isOpen(),
                'closes_at' => $s->closes_at?->toIso8601String(), 'student' => $r->student?->only(['id', 'name_ar', 'name_en']),
                'state' => $response === null ? 'pending' : ($response->decision ?? 'answered'),
                'decision' => $response?->decision,
                'answers' => $response ? $response->answers->mapWithKeys(fn ($a) => [$a->survey_question_id => $a->value]) : (object) [],
                'questions' => $s->questions->map(fn ($q) => $q->only(['id', 'type', 'text', 'options', 'is_required']))->values(),
            ];
        })->sortBy(fn ($r) => [$r['state'] === 'pending' && $r['open'] ? 0 : 1])->values()]);
    }

    public function respond(Request $request, SurveyRecipient $recipient, SurveyService $surveys): JsonResponse
    {
        abort_unless($recipient->user_id === $request->user()->id, 404);
        $data = $request->validate(['decision' => ['nullable', 'in:approved,declined'], 'answers' => ['nullable', 'array']]);

        $response = $surveys->respond($recipient, $data['decision'] ?? null, $data['answers'] ?? []);

        return response()->json(['data' => ['state' => $response->decision ?? 'answered']]);
    }

    /** How many are waiting: the parent home shows it. */
    public function pending(Request $request): JsonResponse
    {
        $count = SurveyRecipient::where('user_id', $request->user()->id)
            ->whereHas('survey', fn ($q) => $q->whereNull('closed_at')->where(fn ($w) => $w->whereNull('closes_at')->orWhere('closes_at', '>', now())))
            ->whereNotExists(function ($q): void {
                $q->from('survey_responses')->whereColumn('survey_responses.survey_id', 'survey_recipients.survey_id')->whereColumn('survey_responses.user_id', 'survey_recipients.user_id')
                    ->where(fn ($w) => $w->whereColumn('survey_responses.student_id', 'survey_recipients.student_id')->orWhere(fn ($n) => $n->whereNull('survey_responses.student_id')->whereNull('survey_recipients.student_id')));
            })->count();

        return response()->json(['data' => ['count' => $count]]);
    }
}
