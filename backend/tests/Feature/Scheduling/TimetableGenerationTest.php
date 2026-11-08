<?php

namespace Tests\Feature\Scheduling;

use App\Central\Actions\SeedTeachers;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Building;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Room;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Services\AutoTeachingAssigner;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\PeriodSetup;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Academic\AcademicTestCase;

class TimetableGenerationTest extends AcademicTestCase
{
    private const PLAN = ['ARB' => 6, 'ENG' => 5, 'MTH' => 5, 'SCI' => 5, 'REL' => 3, 'SOC' => 2, 'CMP' => 2, 'PE' => 2, 'ART' => 1, 'MUS' => 1, 'VOC' => 1, 'ACT' => 2];

    private AcademicYear $year;

    /** @var list<Classroom> */
    private array $classrooms = [];

    /** A school week of 5 days x 7 periods, nine sections of one grade in two buildings, the standard 35-period plan, teachers and their distribution. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $grade = GradeLevel::factory()->create(['code' => '4', 'sort_order' => 7]);
        foreach (self::PLAN as $code => $periods) {
            GradeLevelSubject::create(['grade_level_id' => $grade->id, 'subject_id' => Subject::factory()->create(['code' => $code, 'name_en' => $code])->id, 'weekly_periods' => $periods]);
        }
        foreach (['Boys' => 6, 'Girls' => 3] as $name => $count) {
            $building = Building::create(['name' => $name, 'gender' => 'mixed']);
            $floor = Floor::create(['building_id' => $building->id, 'name' => 'Ground', 'level' => 0]);
            foreach (range(1, $count) as $n) {
                $room = Room::create(['floor_id' => $floor->id, 'name' => "$name-$n", 'kind' => 'classroom', 'type' => 'classroom']);
                $this->classrooms[] = Classroom::factory()->create(['name' => "4-$name-$n", 'grade_level_id' => $grade->id, 'academic_year_id' => $this->year->id, 'room_id' => $room->id]);
            }
        }
        app(PeriodSetup::class)->apply(['days' => [0, 1, 2, 3, 4], 'periods_per_day' => 7, 'starts_at' => '07:45', 'period_minutes' => 45, 'gap_minutes' => 5, 'long_break_after' => 3, 'long_break_minutes' => 20]);
        app(SeedTeachers::class)->handle('t', 'Teacher12345', 24);
        app(AutoTeachingAssigner::class)->handle(24, false);
    }

    private function generate(array $body = [])
    {
        return $this->postJson('/api/timetable/generate', $body);
    }

    public function test_every_section_gets_a_full_week_with_exactly_the_periods_of_the_curriculum(): void
    {
        $this->actAsAdmin();

        $this->generate(['seed' => 1])->assertOk()->assertJsonPath('data.total', 9 * 35)->assertJsonPath('data.placed', 9 * 35)->assertJsonPath('data.unplaced', []);

        foreach ($this->classrooms as $classroom) {
            $entries = TimetableEntry::where('classroom_id', $classroom->id)->with('subject')->get();
            $this->assertCount(35, $entries, $classroom->name);
            foreach (self::PLAN as $code => $periods) {
                $this->assertSame($periods, $entries->filter(fn ($e) => $e->subject->code === $code)->count(), "{$classroom->name} $code");
            }
        }
    }

    public function test_arabic_english_and_maths_are_taught_every_day(): void
    {
        $this->actAsAdmin();
        $this->generate(['seed' => 2])->assertOk();
        $day = Period::pluck('day_of_week', 'id');

        foreach ($this->classrooms as $classroom) {
            foreach (['ARB', 'ENG', 'MTH'] as $code) {
                $days = TimetableEntry::where('classroom_id', $classroom->id)->whereHas('subject', fn ($q) => $q->where('code', $code))->pluck('period_id')->map(fn ($id) => $day[$id])->unique()->sort()->values()->all();
                $this->assertSame([0, 1, 2, 3, 4], $days, "{$classroom->name} $code");
            }
            // The same subject at most twice a day (Arabic has 6 periods), and never more than the cap.
            $perDay = TimetableEntry::where('classroom_id', $classroom->id)->get()->groupBy(fn ($e) => $e->subject_id.'|'.$day[$e->period_id])->map->count();
            $this->assertLessThanOrEqual(2, $perDay->max());
        }
    }

    public function test_nobody_is_in_two_places_at_once_and_a_teacher_never_changes_building_between_two_periods(): void
    {
        $this->actAsAdmin();
        $this->generate(['seed' => 3])->assertOk();

        $this->assertSame(0, DB::table('timetable_entries')->selectRaw('teacher_id, period_id, count(*) n')->groupBy('teacher_id', 'period_id')->havingRaw('count(*) > 1')->get()->count());
        $period = Period::all()->keyBy('id');
        $building = Classroom::with('room.floor')->get()->mapWithKeys(fn ($c) => [$c->id => $c->room->floor->building_id]);
        $byTeacher = TimetableEntry::all()->groupBy('teacher_id');
        foreach ($byTeacher as $entries) {
            $slots = $entries->mapWithKeys(fn ($e) => [$period[$e->period_id]->day_of_week.'-'.$period[$e->period_id]->number => $building[$e->classroom_id]]);
            foreach ($slots as $key => $b) {
                [$d, $n] = explode('-', $key);
                $next = $slots->get("$d-".($n + 1));
                $this->assertTrue($next === null || $next === $b, 'a teacher changes building between two consecutive periods');
            }
        }
        $this->assertSame(1, $byTeacher->map(fn ($e) => $e->map(fn ($x) => $building[$x->classroom_id])->unique()->count())->max());
    }

    public function test_the_entry_has_the_teacher_assigned_to_that_subject_and_the_room_of_the_section(): void
    {
        $this->actAsAdmin();
        $this->generate(['seed' => 4])->assertOk();

        foreach (TimetableEntry::with('classroom')->get() as $entry) {
            $assignment = TeachingAssignment::where('classroom_id', $entry->classroom_id)->where('subject_id', $entry->subject_id)->first();
            $this->assertSame($assignment->teacher_id, $entry->teacher_id);
            $this->assertSame($entry->classroom->room_id, $entry->room_id);
            $this->assertSame($this->year->id, $entry->academic_year_id);
        }
    }

    public function test_a_preview_writes_nothing_and_the_same_seed_gives_the_same_timetable(): void
    {
        $this->actAsAdmin();

        $preview = $this->generate(['seed' => 7, 'dry_run' => true])->assertOk()->assertJsonPath('data.dry_run', true)->assertJsonPath('data.seed', 7);
        $this->assertSame(0, TimetableEntry::count());

        $this->generate(['seed' => 7])->assertOk();
        $first = TimetableEntry::orderBy('classroom_id')->orderBy('period_id')->get(['classroom_id', 'period_id', 'subject_id', 'teacher_id'])->toArray();
        $this->generate(['seed' => 7])->assertOk();
        $second = TimetableEntry::orderBy('classroom_id')->orderBy('period_id')->get(['classroom_id', 'period_id', 'subject_id', 'teacher_id'])->toArray();

        $this->assertSame($first, $second);
        $this->assertSame($preview->json('data.placed'), 9 * 35);
    }

    public function test_generating_again_replaces_the_timetable_and_records_the_run(): void
    {
        $this->actAsAdmin();
        $this->generate(['seed' => 1])->assertOk();
        $this->generate(['seed' => 2])->assertOk();

        $this->assertSame(9 * 35, TimetableEntry::count());
        $this->assertSame(2, DB::table('schedule_generations')->where('kind', 'timetable')->where('status', 'applied')->count());
        $latest = DB::table('schedule_generations')->orderByDesc('id')->first();
        $this->assertSame($latest->id, (int) TimetableEntry::first()->schedule_generation_id);
        $this->assertNotNull($latest->applied_at);
    }

    public function test_locked_entries_stay_and_count_towards_the_curriculum(): void
    {
        $classroom = $this->classrooms[0];
        $period = Period::where('day_of_week', 2)->where('number', 7)->first();
        $subject = Subject::where('code', 'PE')->first();
        $teacher = TeachingAssignment::where('classroom_id', $classroom->id)->where('subject_id', $subject->id)->value('teacher_id');
        $locked = TimetableEntry::create([
            'academic_year_id' => $this->year->id, 'classroom_id' => $classroom->id, 'period_id' => $period->id, 'subject_id' => $subject->id,
            'teacher_id' => $teacher, 'room_id' => $classroom->room_id, 'is_locked' => true,
        ]);
        $this->actAsAdmin();

        $this->generate(['seed' => 5])->assertOk()->assertJsonPath('data.placed', 9 * 35 - 1)->assertJsonPath('data.locked', 1);

        $this->assertTrue(TimetableEntry::whereKey($locked->id)->where('is_locked', true)->exists(), 'the same row, untouched');
        $this->assertSame(2, TimetableEntry::where('classroom_id', $classroom->id)->where('subject_id', $subject->id)->count());
        $this->assertSame(35, TimetableEntry::where('classroom_id', $classroom->id)->count());
    }

    public function test_a_teachers_unavailable_periods_are_respected(): void
    {
        $teacherId = TeachingAssignment::value('teacher_id');
        $periods = Period::where('day_of_week', 0)->pluck('id');
        foreach ($periods as $id) {
            DB::table('teacher_unavailabilities')->insert(['teacher_id' => $teacherId, 'period_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actAsAdmin();

        $this->generate(['seed' => 6])->assertOk();

        $this->assertSame(0, TimetableEntry::where('teacher_id', $teacherId)->whereIn('period_id', $periods)->count(), 'nothing on his day off');
    }

    public function test_a_subject_without_a_teacher_is_reported_and_the_rest_is_still_placed(): void
    {
        TeachingAssignment::where('classroom_id', $this->classrooms[0]->id)->whereHas('subject', fn ($q) => $q->where('code', 'ART'))->delete();
        $this->actAsAdmin();

        $response = $this->generate(['seed' => 1])->assertOk();

        $response->assertJsonPath('data.total', 9 * 35 - 1)->assertJsonPath('data.placed', 9 * 35 - 1);
        $this->assertSame(34, TimetableEntry::where('classroom_id', $this->classrooms[0]->id)->count());
    }

    public function test_it_needs_periods(): void
    {
        Period::query()->delete();
        $this->actAsAdmin();

        $this->generate()->assertUnprocessable();
    }

    public function test_the_timetable_of_a_section_and_of_a_teacher_can_be_read(): void
    {
        $this->actAsAdmin();
        $this->generate(['seed' => 1])->assertOk();
        $classroom = $this->classrooms[0];
        $teacherId = TimetableEntry::where('classroom_id', $classroom->id)->value('teacher_id');

        $section = $this->getJson("/api/timetable?classroom_id={$classroom->id}")->assertOk()->json('data');
        $this->assertCount(35, $section['periods']);
        $this->assertCount(35, $section['entries']);
        $this->assertSame(['id', 'period_id', 'subject', 'teacher', 'classroom', 'room', 'is_locked', 'is_joint'], array_keys($section['entries'][0]));

        $teacher = $this->getJson("/api/timetable?teacher_id=$teacherId")->assertOk()->json('data');
        $this->assertSame(TimetableEntry::where('teacher_id', $teacherId)->count(), count($teacher['entries']));
        $this->getJson('/api/timetable')->assertUnprocessable();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->generate()->assertForbidden();
        $this->getJson('/api/timetable?classroom_id=1')->assertForbidden();
    }
}
