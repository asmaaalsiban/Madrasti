<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Communication\Services\AnnouncementAudience;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyQuestion;
use App\Modules\Surveys\Services\SurveyService;
use App\Shared\Enums\Role;
use App\Shared\Export\XlsxExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Polls, surveys and parent approvals, made by the admin (for all parents or a section) or a teacher (for the sections
 * they teach). A teacher sees only their own; the admin sees all.
 */
class SurveyController extends Controller
{
    public function __construct(private readonly SurveyService $surveys, private readonly AnnouncementAudience $audience) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $rows = Survey::with(['author:id,name', 'classrooms:id,name', 'gradeFrom', 'gradeTo'])->withCount(['recipients', 'responses'])->when(! $user->hasRole(Role::Admin), fn ($q) => $q->where('created_by', $user->id))->latest()->get();

        return response()->json(['data' => $rows->map(fn (Survey $s) => $this->summary($s))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(Survey::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'closes_at' => ['nullable', 'date', 'after:now'],
            'respondents' => ['sometimes', Rule::in(Survey::RESPONDENTS)],
            'audience' => ['required', Rule::in(['all', 'grades', 'classrooms'])],
            'grade_from_id' => ['required_if:audience,grades', 'nullable', 'integer', 'exists:grade_levels,id'],
            'grade_to_id' => ['required_if:audience,grades', 'nullable', 'integer', 'exists:grade_levels,id'],
            'classroom_ids' => ['required_if:audience,classrooms', 'nullable', 'array', 'min:1', 'max:200'],
            'classroom_ids.*' => ['integer', 'distinct', 'exists:classrooms,id'],
            'questions' => ['array', 'max:30'],
            'questions.*.type' => ['required', Rule::in(SurveyQuestion::TYPES)],
            'questions.*.text' => ['required', 'string', 'max:255'],
            'questions.*.is_required' => ['sometimes', 'boolean'],
            'questions.*.options' => ['nullable', 'array', 'max:12'],
            'questions.*.options.*' => ['string', 'max:150'],
        ]);

        // A permission is the parents' to give: students never answer an approval.
        if ($data['type'] === 'approval' && ($data['respondents'] ?? 'parents') !== 'parents') {
            throw ValidationException::withMessages(['respondents' => [__('surveys.approval_parents_only')]]);
        }

        $questions = $data['questions'] ?? [];
        if ($data['type'] !== 'approval' && $questions === []) {
            throw ValidationException::withMessages(['questions' => [__('surveys.needs_question')]]);
        }
        if ($data['type'] === 'poll' && (count($questions) !== 1 || $questions[0]['type'] === 'text')) {
            throw ValidationException::withMessages(['questions' => [__('surveys.poll_one_question')]]);
        }
        foreach ($questions as $i => $q) {
            if (in_array($q['type'], ['single_choice', 'multiple_choice'], true) && count(array_filter(array_map('trim', $q['options'] ?? []))) < 2) {
                throw ValidationException::withMessages(["questions.$i.options" => [__('surveys.needs_options')]]);
            }
        }
        // A teacher may only write to the sections they teach (or are homeroom of).
        if (! $user->hasRole(Role::Admin)) {
            $mine = $this->audience->classroomIds($user);
            abort_unless($data['audience'] === 'classrooms' && collect($data['classroom_ids'])->every(fn ($id) => $mine->contains((int) $id)), 403);
        }

        $survey = $this->surveys->create($user, $data, $questions);
        $survey->load(['classrooms:id,name', 'gradeFrom', 'gradeTo']);

        return response()->json(['data' => $this->summary($survey->loadCount(['recipients', 'responses']))], 201);
    }

    /** The results: who answered and who did not, the count of each choice, and the written answers. */
    public function show(Request $request, Survey $survey): JsonResponse
    {
        $this->owns($request, $survey);
        $survey->load(['questions', 'author:id,name', 'classrooms:id,name', 'gradeFrom', 'gradeTo'])->loadCount(['recipients', 'responses']);
        $responses = $survey->responses()->with('answers')->get()->keyBy(fn ($r) => $r->user_id.'-'.($r->student_id ?? 0));
        $recipients = $survey->recipients()->with(['parent:id,name,phone,role', 'student:id,name_ar,name_en'])->get();
        $rows = $this->rows($survey, $responses, $recipients);

        return response()->json(['data' => $this->summary($survey) + [
            'questions' => $survey->questions->map(fn (SurveyQuestion $q) => $this->question($q, $responses, $recipients)),
            'recipients' => $rows,
            'approved' => $responses->where('decision', 'approved')->count(),
            'declined' => $responses->where('decision', 'declined')->count(),
        ]]);
    }

    /** The responses table as an Excel file (optionally only one state: approved, declined, answered or pending). */
    public function export(Request $request, Survey $survey, XlsxExport $xlsx): BinaryFileResponse
    {
        $this->owns($request, $survey);
        $state = $request->validate(['state' => ['nullable', Rule::in(['approved', 'declined', 'answered', 'pending'])]])['state'] ?? null;
        $survey->load('questions');
        $responses = $survey->responses()->with('answers')->get()->keyBy(fn ($r) => $r->user_id.'-'.($r->student_id ?? 0));
        $recipients = $survey->recipients()->with(['parent:id,name,phone,role', 'student:id,name_ar,name_en'])->get();
        $lang = app()->getLocale() === 'ar' ? 'ar' : 'en';

        $rows = $this->rows($survey, $responses, $recipients)->when($state, fn ($c) => $c->where('state', $state))->map(function (array $r) use ($survey, $lang): array {
            return [
                $r['name'], __('surveys.role_'.$r['role']), $r['phone'], $r['student']["name_$lang"] ?? $r['student']['name_ar'] ?? '', $r['classroom'], __("surveys.state_$r[state]"), $r['answered_at'] ? substr(str_replace('T', ' ', $r['answered_at']), 0, 16) : '',
                ...$survey->questions->map(fn ($q) => $this->plain($r['answers'][$q->id] ?? null))->all(),
            ];
        })->values();

        return $xlsx->download(__('surveys.file').'_'.$survey->id, __('surveys.sheet'), [
            __('surveys.col_name'), __('surveys.col_role'), __('surveys.col_phone'), __('surveys.col_student'), __('surveys.col_classroom'), __('surveys.col_state'), __('surveys.col_answered_at'),
            ...$survey->questions->pluck('text')->all(),
        ], $rows);
    }

    /**
     * Everyone who must answer, with their state, the child's section and the answers they gave; those still waiting first.
     *
     * @param  \Illuminate\Support\Collection<string, \App\Modules\Surveys\Models\SurveyResponse>  $responses
     * @param  \Illuminate\Support\Collection<int, \App\Modules\Surveys\Models\SurveyRecipient>  $recipients
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function rows(Survey $survey, $responses, $recipients)
    {
        $year = AcademicYear::where('is_current', true)->first();
        $sections = Enrollment::where('academic_year_id', $year?->id)->whereIn('student_id', $recipients->pluck('student_id')->filter())->with('classroom:id,name')->get()->pluck('classroom.name', 'student_id');

        return $recipients->map(function ($r) use ($responses, $sections): array {
            $response = $responses->get($r->user_id.'-'.($r->student_id ?? 0));

            return [
                'id' => $r->id, 'name' => $r->parent->name, 'role' => $r->parent->role->value, 'phone' => $r->parent->phone, 'student' => $r->student?->only(['id', 'name_ar', 'name_en']), 'classroom' => $r->student_id ? ($sections[$r->student_id] ?? null) : null,
                'state' => $response === null ? 'pending' : ($response->decision ?? 'answered'), 'answered_at' => $response?->submitted_at?->toIso8601String(),
                'answers' => $response ? $response->answers->mapWithKeys(fn ($a) => [$a->survey_question_id => $a->value])->all() : [],
            ];
        })->sortBy(fn ($r) => [$r['state'] === 'pending' ? 0 : 1, $r['classroom'], $r['name']])->values();
    }

    /** An answer as text for a spreadsheet cell. */
    private function plain(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => __($value ? 'surveys.yes' : 'surveys.no'),
            is_array($value) => implode(', ', $value),
            default => (string) $value,
        };
    }

    public function close(Request $request, Survey $survey): JsonResponse
    {
        $this->owns($request, $survey);
        $survey->update(['closed_at' => $survey->closed_at ?? now()]);

        return response()->json(['data' => $this->summary($survey->loadCount(['recipients', 'responses']))]);
    }

    public function remind(Request $request, Survey $survey): JsonResponse
    {
        $this->owns($request, $survey);

        return response()->json(['data' => ['reminded' => $this->surveys->remind($survey)]]);
    }

    /** A survey nobody has answered can be removed; one with answers is closed instead so the answers are kept. */
    public function destroy(Request $request, Survey $survey): Response
    {
        $this->owns($request, $survey);
        if ($survey->responses()->exists()) {
            throw ValidationException::withMessages(['survey' => [__('surveys.has_answers')]]);
        }
        $survey->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function owns(Request $request, Survey $survey): void
    {
        $user = $this->user($request);
        abort_unless($user->hasRole(Role::Admin) || $survey->created_by === $user->id, 403);
    }

    /** @return array<string, mixed> */
    private function summary(Survey $s): array
    {
        $status = $s->closed_at !== null || ($s->closes_at !== null && $s->closes_at->isPast()) ? 'closed' : 'open';

        return [
            'id' => $s->id, 'type' => $s->type, 'title' => $s->title, 'description' => $s->description, 'audience' => $s->audience, 'respondents' => $s->respondents,
            'audience_detail' => match ($s->audience) {
                'grades' => ['from' => $s->gradeFrom?->only(['name_ar', 'name_en']), 'to' => $s->gradeTo?->only(['name_ar', 'name_en'])],
                'classrooms' => $s->classrooms->pluck('name')->sort()->values(),
                default => null,
            },
            'status' => $status, 'closes_at' => $s->closes_at?->toIso8601String(), 'created_at' => $s->created_at?->toIso8601String(), 'author' => $s->author?->name,
            'recipient_count' => (int) ($s->recipients_count ?? 0), 'response_count' => (int) ($s->responses_count ?? 0),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, \App\Modules\Surveys\Models\SurveyResponse>  $responses
     * @param  \Illuminate\Support\Collection<int, \App\Modules\Surveys\Models\SurveyRecipient>  $recipients
     * @return array<string, mixed>
     */
    private function question(SurveyQuestion $q, $responses, $recipients): array
    {
        $values = DB::table('survey_answers')->where('survey_question_id', $q->id)->get();
        $base = ['id' => $q->id, 'type' => $q->type, 'text' => $q->text, 'options' => $q->options, 'is_required' => $q->is_required, 'answered' => $values->count()];
        if ($q->type === 'text') {
            return $base + ['texts' => $values->map(fn ($a) => json_decode($a->value, true))->values()];
        }
        $choices = $q->type === 'yes_no' ? [true, false] : ($q->options ?? []);
        $decoded = $values->map(fn ($a) => json_decode($a->value, true));
        $counts = [];
        foreach ($choices as $choice) {
            $key = is_bool($choice) ? ($choice ? 'yes' : 'no') : $choice;
            $counts[] = ['option' => $key, 'count' => $decoded->filter(fn ($v) => is_array($v) ? in_array($choice, $v, true) : $v === $choice)->count()];
        }

        return $base + ['counts' => $counts];
    }
}
