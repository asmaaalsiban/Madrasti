<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Http\Requests\SaveCurriculumRequest;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Services\StandardCurriculum;
use App\Modules\Scheduling\Services\PeriodSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** The curriculum: which subjects every grade has and their periods per week (admin). */
class CurriculumController extends Controller
{
    private const DEFAULT_PERIODS_PER_WEEK = 35; // 7 periods x 5 days

    public function show(): JsonResponse
    {
        Gate::authorize('viewAny', GradeLevelSubject::class);

        return $this->state();
    }

    public function save(SaveCurriculumRequest $request): JsonResponse
    {
        Gate::authorize('update', new GradeLevelSubject);

        DB::transaction(function () use ($request): void {
            if ($request->has('periods_per_week')) {
                DB::table('school_settings')->updateOrInsert(
                    ['key' => 'periods_per_week'],
                    ['value' => json_encode($request->integer('periods_per_week')), 'created_at' => now(), 'updated_at' => now()],
                );
            }
            $desired = collect($request->validated('cells'))->filter(fn ($c) => (int) ($c['weekly_periods'] ?? 0) > 0)
                ->mapWithKeys(fn ($c) => [$c['grade_level_id'].'-'.$c['subject_id'] => $c]);
            $this->replace($desired->map(fn ($c) => [(int) $c['grade_level_id'], (int) $c['subject_id'], (int) $c['weekly_periods']])->values()->all(), null);
        });

        return $this->state();
    }

    /** Gives the other grades the same subjects and periods as one grade (what they had is replaced). */
    public function copy(Request $request): JsonResponse
    {
        Gate::authorize('update', new GradeLevelSubject);
        $data = $request->validate([
            'from_grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'],
            'to_grade_level_ids' => ['required', 'array', 'min:1'],
            'to_grade_level_ids.*' => ['integer', 'exists:grade_levels,id', 'different:from_grade_level_id'],
        ]);

        $source = GradeLevelSubject::where('grade_level_id', $data['from_grade_level_id'])->get();
        DB::transaction(function () use ($data, $source): void {
            $cells = [];
            foreach ($data['to_grade_level_ids'] as $to) {
                foreach ($source as $row) {
                    $cells[] = [(int) $to, $row->subject_id, $row->weekly_periods];
                }
            }
            $this->replace($cells, array_map('intval', $data['to_grade_level_ids']));
        });

        return response()->json(['data' => ['copied' => count($data['to_grade_level_ids'])]]);
    }

    /** Adds the standard subjects that are missing and applies the standard plan to grades 1 to 10 the school has. */
    public function standardPlan(): JsonResponse
    {
        Gate::authorize('update', new GradeLevelSubject);

        $result = DB::transaction(function (): array {
            $created = 0;
            $subjects = [];
            foreach (StandardCurriculum::SUBJECTS as $code => [$ar, $en]) {
                $subject = Subject::firstOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en]);
                $created += $subject->wasRecentlyCreated ? 1 : 0;
                $subjects[$code] = $subject->id;
            }

            $applied = [];
            $missing = [];
            foreach (StandardCurriculum::plan() as $gradeCode => $periods) {
                $grade = GradeLevel::where('code', $gradeCode)->orWhere('name_en', 'Grade '.$gradeCode)->orderBy('id')->first();
                if ($grade === null) {
                    $missing[] = (string) $gradeCode;

                    continue;
                }
                $cells = [];
                foreach ($periods as $code => $n) {
                    $cells[] = [$grade->id, $subjects[$code], $n];
                }
                $this->replace($cells, [$grade->id]);
                if (isset(StandardCurriculum::PERIODS_PER_DAY[$gradeCode]) && $grade->periods_per_day === null) {
                    $grade->update(['periods_per_day' => StandardCurriculum::PERIODS_PER_DAY[$gradeCode]]); // a value the school chose is kept
                }
                $applied[] = (string) $gradeCode;
            }

            return ['subjects_created' => $created, 'applied' => $applied, 'missing_grades' => $missing];
        });

        return response()->json(['data' => $result]);
    }

    /**
     * Makes the curriculum equal to $cells: adds, updates and removes rows. With $grades only those grades are touched.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $cells  [grade level id, subject id, periods]
     * @param  list<int>|null  $grades
     */
    private function replace(array $cells, ?array $grades): void
    {
        $wanted = collect($cells)->mapWithKeys(fn ($c) => ["$c[0]-$c[1]" => $c]);
        $existing = GradeLevelSubject::query()->when($grades !== null, fn ($q) => $q->whereIn('grade_level_id', $grades))->get();

        foreach ($existing as $row) {
            $key = $row->grade_level_id.'-'.$row->subject_id;
            if (! $wanted->has($key)) {
                $row->delete();
            } elseif ($row->weekly_periods !== $wanted[$key][2]) {
                $row->update(['weekly_periods' => $wanted[$key][2]]);
            }
        }
        foreach ($wanted->diffKeys($existing->mapWithKeys(fn ($r) => [$r->grade_level_id.'-'.$r->subject_id => true])) as [$grade, $subject, $periods]) {
            GradeLevelSubject::create(['grade_level_id' => $grade, 'subject_id' => $subject, 'weekly_periods' => $periods]);
        }
    }

    private function state(): JsonResponse
    {
        $cells = GradeLevelSubject::get(['grade_level_id', 'subject_id', 'weekly_periods']);
        $setting = DB::table('school_settings')->where('key', 'periods_per_week')->value('value');
        $schoolDay = app(PeriodSetup::class)->current();

        return response()->json(['data' => [
            'periods_per_week' => $setting !== null ? (int) json_decode($setting, true) : self::DEFAULT_PERIODS_PER_WEEK,
            // Every grade is compared with its own week: its periods a day (or the school's) x the school days.
            'grades' => GradeLevel::orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en', 'code', 'sort_order', 'periods_per_day'])
                ->map(fn (GradeLevel $g) => $g->toArray() + ['weekly_target' => ($g->periods_per_day ?? (int) $schoolDay['periods_per_day']) * count($schoolDay['days'])]),
            'subjects' => Subject::orderBy('name_en')->get(['id', 'code', 'name_ar', 'name_en']),
            'cells' => $cells,
            'totals' => $cells->groupBy('grade_level_id')->map(fn ($rows) => (int) $rows->sum('weekly_periods')),
        ]]);
    }
}
