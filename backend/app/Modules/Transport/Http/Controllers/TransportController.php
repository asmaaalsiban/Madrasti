<?php

declare(strict_types=1);

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Transport\Models\Bus;
use App\Modules\Transport\Models\StudentTransport;
use App\Modules\Transport\Models\TransportRound;
use App\Shared\Enums\StaffType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * School transport. The principal sets the rounds (as many as the school has), the buses (driver, area, seats, rounds) and where each student rides:
 * a round, a bus that picks them up and a bus that takes them home (they may differ, or only one may be set). Parents and students
 * read their own.
 */
class TransportController extends Controller
{
    public function overview(): JsonResponse
    {
        $rounds = TransportRound::orderBy('sort_order')->orderBy('id')->get();
        $buses = Bus::with(['rounds:id', 'driver:id,name,phone'])->orderByRaw('cast(number as unsigned), number')->get();
        $rides = StudentTransport::all(['pickup_round_id', 'pickup_bus_id', 'dropoff_round_id', 'dropoff_bus_id']);

        return response()->json([
            'rounds' => $rounds->map(fn ($r) => $this->round($r, $this->roundRiders($r->id)))->values(),
            'buses' => $buses->map(fn ($b) => $this->bus($b, $rides))->values(),
            'riders' => $rides->count(),
            // Who can be chosen as a driver or an escort: the school's own staff.
            'drivers' => $this->staffWhere([StaffType::Driver->value]),
            'escorts' => $this->staffWhere([...StaffType::valuesIn('teaching'), StaffType::BusSupervisor->value]),
        ]);
    }

    /**
     * The buses of the signed-in driver or escort: their rounds and, round by round, the students to pick up and to take home
     * (with the section and the parent's name and phone), the escort for the driver and the driver for the escort.
     */
    public function mine(Request $request): JsonResponse
    {
        $me = $request->user();
        $buses = Bus::with(['rounds', 'driver:id,name,phone'])->where('is_active', true)
            ->where(fn ($q) => $q->where('driver_id', $me->id)->orWhereHas('rounds', fn ($r) => $r->where('bus_round.escort_id', $me->id)))->orderByRaw('cast(number as unsigned), number')->get();
        $rides = StudentTransport::with(['student.guardians', 'student.enrollments' => fn ($e) => $e->whereHas('classroom.academicYear', fn ($y) => $y->where('is_current', true))->with('classroom:id,name')])
            ->where(fn ($q) => $q->whereIn('pickup_bus_id', $buses->pluck('id'))->orWhereIn('dropoff_bus_id', $buses->pluck('id')))->get();
        $rider = function (StudentTransport $r): array {
            $parent = $r->student->guardians->sortByDesc(fn ($g) => (int) $g->pivot->is_primary)->first();

            return $this->student($r->student) + ['classroom' => $r->student->enrollments->first()?->classroom?->name, 'parent_name' => $parent?->name, 'parent_phone' => $parent?->phone];
        };

        return response()->json(['data' => $buses->map(fn (Bus $b) => [
            'id' => $b->id, 'number' => $b->number, 'area_from' => $b->area_from, 'area_to' => $b->area_to, 'capacity' => $b->capacity, 'notes' => $b->notes,
            'my_part' => $b->driver_id === $me->id ? 'driver' : 'escort',
            'driver' => $this->person($b->driver, $b->driver_name, $b->driver_phone),
            // The driver sees every round of his bus; an escort only the rounds she is the escort of.
            'rounds' => $b->rounds->filter(fn (TransportRound $round) => $b->driver_id === $me->id || (int) $round->pivot->escort_id === $me->id)->sortBy([['period', 'desc'], ['sort_order', 'asc']])->map(fn (TransportRound $round) => $this->round($round, 0) + [
                'escort' => $this->person($b->escortFor((int) $round->id)),
                'students' => $rides->filter(fn ($r) => ($r->pickup_bus_id === $b->id && $r->pickup_round_id === $round->id) || ($r->dropoff_bus_id === $b->id && $r->dropoff_round_id === $round->id))
                    ->map($rider)->sortBy('name_ar')->values(),
            ])->values(),
        ])->values()]);
    }

    /** @param  list<string>  $types
     * @return list<array{id: int, name: string, phone: ?string}> */
    private function staffWhere(array $types): array
    {
        return User::where('is_active', true)->whereHas('staffProfile', fn ($q) => $q->whereIn('staff_type', $types))->orderBy('name')->get(['id', 'name', 'phone'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone])->values()->all();
    }

    /** @return array{id: ?int, name: string, phone: ?string}|null */
    private function person(?User $u, ?string $fallbackName = null, ?string $fallbackPhone = null): ?array
    {
        if ($u !== null) {
            return ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone];
        }

        return $fallbackName ? ['id' => null, 'name' => $fallbackName, 'phone' => $fallbackPhone] : null;
    }

    public function storeRound(Request $request): JsonResponse
    {
        $round = TransportRound::create($this->roundData($request) + ['sort_order' => (int) TransportRound::max('sort_order') + 1]);

        return response()->json(['data' => $this->round($round, 0)], 201);
    }

    public function updateRound(Request $request, TransportRound $round): JsonResponse
    {
        $data = $this->roundData($request);
        if ($data['period'] !== $round->period && $this->roundRiders($round->id) > 0) {
            throw ValidationException::withMessages(['period' => [__('transport.round_in_use')]]);
        }
        $round->update($data);

        return response()->json(['data' => $this->round($round, $this->roundRiders($round->id))]);
    }

    public function destroyRound(TransportRound $round): Response
    {
        if ($this->roundRiders($round->id) > 0) {
            throw ValidationException::withMessages(['round' => [__('transport.round_in_use')]]);
        }
        $round->delete();

        return response()->noContent();
    }

    public function storeBus(Request $request): JsonResponse
    {
        $data = $this->withDriverDetails($this->busData($request));
        $bus = Bus::create(collect($data)->except(['round_ids', 'round_escorts'])->all());
        $this->syncRounds($bus, $data['round_ids'] ?? [], $data['round_escorts'] ?? []);

        return response()->json(['data' => $this->bus($bus->load(['rounds:id', 'driver:id,name,phone']), collect())], 201);
    }

    public function updateBus(Request $request, Bus $bus): JsonResponse
    {
        $data = $this->withDriverDetails($this->busData($request, $bus));
        $rides = StudentTransport::all(['pickup_round_id', 'pickup_bus_id', 'dropoff_round_id', 'dropoff_bus_id']);
        if (array_key_exists('round_ids', $data)) {
            // A round cannot be taken away from a bus that still carries students in it.
            $dropped = $bus->rounds()->pluck('transport_rounds.id')->diff($data['round_ids']);
            if ($rides->filter(fn ($s) => ($s->pickup_bus_id === $bus->id && $dropped->contains($s->pickup_round_id)) || ($s->dropoff_bus_id === $bus->id && $dropped->contains($s->dropoff_round_id)))->isNotEmpty()) {
                throw ValidationException::withMessages(['round_ids' => [__('transport.bus_round_in_use')]]);
            }
        }
        if (isset($data['capacity']) && $this->busiest($bus->id, $rides) > $data['capacity']) {
            throw ValidationException::withMessages(['capacity' => [__('transport.capacity_below_riders')]]);
        }
        $bus->update(collect($data)->except(['round_ids', 'round_escorts'])->all());
        if (array_key_exists('round_ids', $data) || array_key_exists('round_escorts', $data)) {
            $this->syncRounds($bus, $data['round_ids'] ?? $bus->rounds()->pluck('transport_rounds.id')->all(), $data['round_escorts'] ?? null);
        }

        return response()->json(['data' => $this->bus($bus->refresh()->load(['rounds:id', 'driver:id,name,phone']), $rides)]);
    }

    public function destroyBus(Bus $bus): Response
    {
        if (StudentTransport::where('pickup_bus_id', $bus->id)->orWhere('dropoff_bus_id', $bus->id)->exists()) {
            throw ValidationException::withMessages(['bus' => [__('transport.bus_in_use')]]);
        }
        $bus->delete();

        return response()->noContent();
    }

    /** Who rides a bus, per round: one entry per student and way (to school / home). */
    public function riders(Bus $bus): JsonResponse
    {
        $rows = StudentTransport::with(['student:id,name_ar,name_en,student_number'])->where(fn ($q) => $q->where('pickup_bus_id', $bus->id)->orWhere('dropoff_bus_id', $bus->id))->get();
        $entries = $rows->flatMap(fn ($r) => array_values(array_filter([
            $r->pickup_bus_id === $bus->id ? ['student' => $this->student($r->student), 'round_id' => $r->pickup_round_id, 'direction' => 'pickup'] : null,
            $r->dropoff_bus_id === $bus->id ? ['student' => $this->student($r->student), 'round_id' => $r->dropoff_round_id, 'direction' => 'dropoff'] : null,
        ])));

        return response()->json(['data' => $entries->values()]);
    }

    /** Every student with a ride (and a search over all students, to give one a ride). */
    public function assignments(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('search', ''));
        $students = Student::query()->with(['transport.pickupRound', 'transport.dropoffRound', 'transport.pickupBus', 'transport.dropoffBus', 'enrollments' => fn ($e) => $e->whereHas('classroom.academicYear', fn ($y) => $y->where('is_current', true))->with('classroom:id,name')])
            ->when($q !== '', fn ($s) => $s->where(fn ($w) => $w->where('name_ar', 'like', "%$q%")->orWhere('name_en', 'like', "%$q%")->orWhere('student_number', 'like', "%$q%")))
            ->when($request->query('only') === 'riding', fn ($s) => $s->whereHas('transport'))
            ->when($request->query('only') === 'none', fn ($s) => $s->whereDoesntHave('transport'))
            ->orderBy('name_ar')->limit(100)->get();

        return response()->json(['data' => $students->map(fn ($s) => $this->student($s) + ['classroom' => $s->enrollments->first()?->classroom?->name, 'ride' => $this->ride($s->transport)])->values()]);
    }

    /** A ride has two ways, each with its own round and bus: to school in a morning round, home in an evening round. */
    public function assign(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'pickup_round_id' => ['nullable', 'integer', 'required_with:pickup_bus_id', 'exists:transport_rounds,id'],
            'pickup_bus_id' => ['nullable', 'integer', 'required_with:pickup_round_id', 'exists:buses,id'],
            'dropoff_round_id' => ['nullable', 'integer', 'required_with:dropoff_bus_id', 'exists:transport_rounds,id'],
            'dropoff_bus_id' => ['nullable', 'integer', 'required_with:dropoff_round_id', 'exists:buses,id'],
        ]);
        if (empty($data['pickup_bus_id']) && empty($data['dropoff_bus_id'])) {
            throw ValidationException::withMessages(['pickup_bus_id' => [__('transport.need_a_bus')]]);
        }
        foreach (['pickup' => 'morning', 'dropoff' => 'evening'] as $way => $period) {
            if (! empty($data["{$way}_bus_id"])) {
                $this->checkSeat((int) $data["{$way}_bus_id"], (int) $data["{$way}_round_id"], $period, $student->id, $way);
            }
        }
        $ride = StudentTransport::updateOrCreate(['student_id' => $student->id], [
            'pickup_round_id' => $data['pickup_bus_id'] ?? null ? $data['pickup_round_id'] : null, 'pickup_bus_id' => $data['pickup_bus_id'] ?? null,
            'dropoff_round_id' => $data['dropoff_bus_id'] ?? null ? $data['dropoff_round_id'] : null, 'dropoff_bus_id' => $data['dropoff_bus_id'] ?? null,
        ]);

        return response()->json(['data' => $this->ride($ride->load(['pickupRound', 'dropoffRound', 'pickupBus', 'dropoffBus']))]);
    }

    public function unassign(Student $student): Response
    {
        StudentTransport::where('student_id', $student->id)->get()->each->delete();

        return response()->noContent();
    }

    /** A student's ride: the principal, the student and their parents. */
    public function forStudent(Student $student): JsonResponse
    {
        Gate::authorize('view-student-transport', $student);

        return response()->json(['data' => $this->ride(StudentTransport::with(['pickupRound', 'dropoffRound', 'pickupBus', 'dropoffBus'])->where('student_id', $student->id)->first())]);
    }

    /** The round must be of the right period (morning going, evening coming back); the bus must be active and have a free seat (the student's own seat does not count). */
    private function checkSeat(int $busId, int $roundId, string $period, int $studentId, string $way): void
    {
        $field = "{$way}_bus_id";
        $bus = Bus::findOrFail($busId);
        if (TransportRound::findOrFail($roundId)->period !== $period) {
            throw ValidationException::withMessages(["{$way}_round_id" => [__("transport.wrong_period_$period")]]);
        }
        if (! $bus->is_active) {
            throw ValidationException::withMessages([$field => [__('transport.bus_inactive')]]);
        }
        // A bus that did not serve the round yet starts serving it (the principal chose it for this student).
        $bus->rounds()->syncWithoutDetaching([$roundId]);
        $taken = StudentTransport::where("{$way}_round_id", $roundId)->where($field, $busId)->where('student_id', '!=', $studentId)->count();
        if ($taken >= $bus->capacity) {
            throw ValidationException::withMessages([$field => [__('transport.bus_full')]]);
        }
    }

    /** The most students the bus carries at once (in one round, one way). */
    private function busiest(int $busId, $rides): int
    {
        return (int) $rides->flatMap(fn ($s) => array_filter([
            $s->pickup_bus_id === $busId ? "p{$s->pickup_round_id}" : null,
            $s->dropoff_bus_id === $busId ? "d{$s->dropoff_round_id}" : null,
        ]))->countBy()->max();
    }

    /** Rides that use a round, going or coming back. */
    private function roundRiders(int $roundId): int
    {
        return StudentTransport::where('pickup_round_id', $roundId)->count() + StudentTransport::where('dropoff_round_id', $roundId)->count();
    }

    /** @return array<string, mixed> */
    private function roundData(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:60'], 'name_en' => ['required', 'string', 'max:60'], 'period' => ['required', 'in:morning,evening'],
            'starts_at' => ['nullable', 'date_format:H:i'], 'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
        ]);
    }

    /**
     * Sets the rounds of the bus and the escort of each. A round with no escort sent keeps the one it has.
     *
     * @param  array<int|string, mixed>  $roundIds
     * @param  array<int|string, mixed>|null  $escorts  {round id: user id|null}
     */
    private function syncRounds(Bus $bus, array $roundIds, ?array $escorts): void
    {
        $current = DB::table('bus_round')->where('bus_id', $bus->id)->pluck('escort_id', 'transport_round_id');
        $stray = collect($escorts ?? [])->keys()->map(fn ($k) => (int) $k)->diff(collect($roundIds)->map(fn ($r) => (int) $r));
        if ($stray->isNotEmpty()) {
            throw ValidationException::withMessages(['round_escorts' => [__('transport.escort_round_not_on_bus')]]);
        }
        $bus->rounds()->sync(collect($roundIds)->mapWithKeys(fn ($id) => [(int) $id => ['escort_id' => $escorts !== null && array_key_exists($id, $escorts) ? ($escorts[$id] ?: null) : ($current[(int) $id] ?? null)]])->all());
    }

    /** @return array<string, mixed> */
    private function busData(Request $request, ?Bus $bus = null): array
    {
        $new = $bus === null;

        return $request->validate([
            'number' => [$new ? 'required' : 'sometimes', 'string', 'max:20', 'unique:buses,number'.($bus ? ",{$bus->id}" : '')],
            // The driver is chosen from the staff (position "driver"); a name typed by hand still works for a driver who is not registered.
            'driver_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                $this->mustBeStaff($value, [StaffType::Driver->value], $fail);
            }],
            // One escort per round of the bus: {round id: user id or null}. Each round has its own escort.
            'round_escorts' => ['sometimes', 'array'],
            'round_escorts.*' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                $this->mustBeStaff($value, [...StaffType::valuesIn('teaching'), StaffType::BusSupervisor->value], $fail);
                if ($value !== null && $value !== '' && (int) $value === (int) $request->input('driver_id')) {
                    $fail(__('transport.not_eligible'));
                }
            }],
            'driver_name' => [$new ? 'required_without:driver_id' : 'sometimes', 'nullable', 'string', 'max:120'],
            'driver_phone' => ['nullable', 'string', 'max:30'],
            'capacity' => [$new ? 'required' : 'sometimes', 'integer', 'min:1', 'max:100'],
            'area_from' => [$new ? 'required' : 'sometimes', 'string', 'max:150'],
            'area_to' => [$new ? 'required' : 'sometimes', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'round_ids' => ['sometimes', 'array'], 'round_ids.*' => ['integer', 'exists:transport_rounds,id'],
        ]);
    }

    /** An active member of staff with one of the positions, or the message that says who may be chosen. */
    private function mustBeStaff(mixed $id, array $types, \Closure $fail): void
    {
        if ($id === null || $id === '') {
            return;
        }
        $ok = User::whereKey($id)->where('is_active', true)->whereHas('staffProfile', fn ($q) => $q->whereIn('staff_type', $types))->exists();
        $ok || $fail(__('transport.not_eligible'));
    }

    /** A driver chosen from the staff gives the bus his name and phone (so every screen shows them). */
    private function withDriverDetails(array $data): array
    {
        if (! empty($data['driver_id'])) {
            $driver = User::findOrFail($data['driver_id']);
            $data['driver_name'] = $driver->name;
            $data['driver_phone'] = $driver->phone;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function round(TransportRound $r, int $riders): array
    {
        return ['id' => $r->id, 'name_ar' => $r->name_ar, 'name_en' => $r->name_en, 'period' => $r->period, 'starts_at' => $r->starts_at ? substr((string) $r->starts_at, 0, 5) : null, 'ends_at' => $r->ends_at ? substr((string) $r->ends_at, 0, 5) : null, 'riders' => $riders];
    }

    /** @return array<string, mixed> */
    private function bus(Bus $b, $rides): array
    {
        $roundIds = $b->rounds->pluck('id')->values();

        return [
            'id' => $b->id, 'number' => $b->number, 'driver_id' => $b->driver_id, 'driver' => $this->person($b->driver, $b->driver_name, $b->driver_phone),
            // The escort of each round: {round id: {id, name, phone} or null}.
            'round_escorts' => $b->rounds->mapWithKeys(fn ($r) => [$r->id => $this->person($b->escortFor((int) $r->id))]),
            'driver_name' => $b->driver_name, 'driver_phone' => $b->driver_phone, 'capacity' => $b->capacity,
            'area_from' => $b->area_from, 'area_to' => $b->area_to, 'notes' => $b->notes, 'is_active' => $b->is_active, 'round_ids' => $roundIds,
            // Seats taken in each round the bus serves (a round is either morning or evening, so one number).
            'load' => $roundIds->mapWithKeys(fn ($id) => [$id => $rides->where('pickup_round_id', $id)->where('pickup_bus_id', $b->id)->count() + $rides->where('dropoff_round_id', $id)->where('dropoff_bus_id', $b->id)->count()]),
        ];
    }

    /** @return array<string, mixed> */
    private function student(Student $s): array
    {
        return ['id' => $s->id, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en, 'student_number' => $s->student_number];
    }

    /** @return array<string, mixed>|null */
    private function ride(?StudentTransport $r): ?array
    {
        if ($r === null) {
            return null;
        }
        $bus = fn (?Bus $b, ?int $roundId) => $b ? ['id' => $b->id, 'number' => $b->number, 'driver_name' => $b->driver_name, 'driver_phone' => $b->driver_phone, 'escort_name' => $roundId ? $b->escortFor($roundId)?->name : null, 'escort_phone' => $roundId ? $b->escortFor($roundId)?->phone : null, 'area_from' => $b->area_from, 'area_to' => $b->area_to] : null;

        return [
            'pickup_round' => $r->pickupRound ? $this->round($r->pickupRound, 0) : null, 'pickup_bus' => $bus($r->pickupBus, $r->pickup_round_id),
            'dropoff_round' => $r->dropoffRound ? $this->round($r->dropoffRound, 0) : null, 'dropoff_bus' => $bus($r->dropoffBus, $r->dropoff_round_id),
        ];
    }
}
