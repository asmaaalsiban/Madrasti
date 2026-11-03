<?php

declare(strict_types=1);

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\Student;
use App\Modules\Transport\Models\Bus;
use App\Modules\Transport\Models\StudentTransport;
use App\Modules\Transport\Models\TransportRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
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
        $buses = Bus::with('rounds:id')->orderByRaw('cast(number as unsigned), number')->get();
        $rides = StudentTransport::all(['transport_round_id', 'pickup_bus_id', 'dropoff_bus_id']);

        return response()->json([
            'rounds' => $rounds->map(fn ($r) => $this->round($r, $rides->where('transport_round_id', $r->id)->count()))->values(),
            'buses' => $buses->map(fn ($b) => $this->bus($b, $rides))->values(),
            'riders' => $rides->count(),
        ]);
    }

    public function storeRound(Request $request): JsonResponse
    {
        $round = TransportRound::create($this->roundData($request) + ['sort_order' => (int) TransportRound::max('sort_order') + 1]);

        return response()->json(['data' => $this->round($round, 0)], 201);
    }

    public function updateRound(Request $request, TransportRound $round): JsonResponse
    {
        $round->update($this->roundData($request));

        return response()->json(['data' => $this->round($round, StudentTransport::where('transport_round_id', $round->id)->count())]);
    }

    public function destroyRound(TransportRound $round): Response
    {
        if (StudentTransport::where('transport_round_id', $round->id)->exists()) {
            throw ValidationException::withMessages(['round' => [__('transport.round_in_use')]]);
        }
        $round->delete();

        return response()->noContent();
    }

    public function storeBus(Request $request): JsonResponse
    {
        $data = $this->busData($request);
        $bus = Bus::create(collect($data)->except('round_ids')->all());
        $bus->rounds()->sync($data['round_ids'] ?? []);

        return response()->json(['data' => $this->bus($bus->load('rounds:id'), collect())], 201);
    }

    public function updateBus(Request $request, Bus $bus): JsonResponse
    {
        $data = $this->busData($request, $bus);
        $rides = StudentTransport::all(['transport_round_id', 'pickup_bus_id', 'dropoff_bus_id']);
        if (array_key_exists('round_ids', $data)) {
            // A round cannot be taken away from a bus that still carries students in it.
            $dropped = $bus->rounds()->pluck('transport_rounds.id')->diff($data['round_ids']);
            if ($rides->filter(fn ($s) => $dropped->contains($s->transport_round_id) && ($s->pickup_bus_id === $bus->id || $s->dropoff_bus_id === $bus->id))->isNotEmpty()) {
                throw ValidationException::withMessages(['round_ids' => [__('transport.bus_round_in_use')]]);
            }
        }
        if (isset($data['capacity']) && $this->busiest($bus->id, $rides) > $data['capacity']) {
            throw ValidationException::withMessages(['capacity' => [__('transport.capacity_below_riders')]]);
        }
        $bus->update(collect($data)->except('round_ids')->all());
        if (array_key_exists('round_ids', $data)) {
            $bus->rounds()->sync($data['round_ids']);
        }

        return response()->json(['data' => $this->bus($bus->refresh()->load('rounds:id'), $rides)]);
    }

    public function destroyBus(Bus $bus): Response
    {
        if (StudentTransport::where('pickup_bus_id', $bus->id)->orWhere('dropoff_bus_id', $bus->id)->exists()) {
            throw ValidationException::withMessages(['bus' => [__('transport.bus_in_use')]]);
        }
        $bus->delete();

        return response()->noContent();
    }

    /** Who rides a bus, per round, going and coming back. */
    public function riders(Bus $bus): JsonResponse
    {
        $rows = StudentTransport::with(['student:id,name_ar,name_en,student_number'])->where(fn ($q) => $q->where('pickup_bus_id', $bus->id)->orWhere('dropoff_bus_id', $bus->id))->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'student' => $this->student($r->student), 'round_id' => $r->transport_round_id,
            'pickup' => $r->pickup_bus_id === $bus->id, 'dropoff' => $r->dropoff_bus_id === $bus->id,
        ])->values()]);
    }

    /** Every student with a ride (and a search over all students, to give one a ride). */
    public function assignments(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('search', ''));
        $students = Student::query()->with(['transport.round', 'transport.pickupBus', 'transport.dropoffBus', 'enrollments' => fn ($e) => $e->whereHas('classroom.academicYear', fn ($y) => $y->where('is_current', true))->with('classroom:id,name')])
            ->when($q !== '', fn ($s) => $s->where(fn ($w) => $w->where('name_ar', 'like', "%$q%")->orWhere('name_en', 'like', "%$q%")->orWhere('student_number', 'like', "%$q%")))
            ->when($request->query('only') === 'riding', fn ($s) => $s->whereHas('transport'))
            ->when($request->query('only') === 'none', fn ($s) => $s->whereDoesntHave('transport'))
            ->orderBy('name_ar')->limit(100)->get();

        return response()->json(['data' => $students->map(fn ($s) => $this->student($s) + ['classroom' => $s->enrollments->first()?->classroom?->name, 'ride' => $this->ride($s->transport)])->values()]);
    }

    public function assign(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'round_id' => ['required', 'integer', 'exists:transport_rounds,id'],
            'pickup_bus_id' => ['nullable', 'integer', 'exists:buses,id'],
            'dropoff_bus_id' => ['nullable', 'integer', 'exists:buses,id'],
        ]);
        $pickup = $data['pickup_bus_id'] ?? null;
        $dropoff = $data['dropoff_bus_id'] ?? null;
        if ($pickup === null && $dropoff === null) {
            throw ValidationException::withMessages(['pickup_bus_id' => [__('transport.need_a_bus')]]);
        }
        foreach (['pickup_bus_id' => $pickup, 'dropoff_bus_id' => $dropoff] as $field => $busId) {
            if ($busId !== null) {
                $this->checkSeat((int) $busId, (int) $data['round_id'], $student->id, $field === 'pickup_bus_id' ? 'pickup_bus_id' : 'dropoff_bus_id', $field);
            }
        }
        $ride = StudentTransport::updateOrCreate(['student_id' => $student->id], ['transport_round_id' => $data['round_id'], 'pickup_bus_id' => $pickup, 'dropoff_bus_id' => $dropoff]);

        return response()->json(['data' => $this->ride($ride->load(['round', 'pickupBus', 'dropoffBus']))]);
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

        return response()->json(['data' => $this->ride(StudentTransport::with(['round', 'pickupBus', 'dropoffBus'])->where('student_id', $student->id)->first())]);
    }

    /** The bus must exist for the round, be active and have a free seat (the student's own seat does not count). */
    private function checkSeat(int $busId, int $roundId, int $studentId, string $column, string $field): void
    {
        $bus = Bus::with('rounds:id')->findOrFail($busId);
        if (! $bus->is_active) {
            throw ValidationException::withMessages([$field => [__('transport.bus_inactive')]]);
        }
        // A bus that did not serve the round yet starts serving it (the principal chose it for this student).
        $bus->rounds()->syncWithoutDetaching([$roundId]);
        $taken = StudentTransport::where('transport_round_id', $roundId)->where($column, $busId)->where('student_id', '!=', $studentId)->count();
        if ($taken >= $bus->capacity) {
            throw ValidationException::withMessages([$field => [__('transport.bus_full')]]);
        }
    }

    /** The most students the bus carries at once (in one round, one direction). */
    private function busiest(int $busId, $rides): int
    {
        return (int) $rides->flatMap(fn ($s) => array_filter([
            $s->pickup_bus_id === $busId ? "p{$s->transport_round_id}" : null,
            $s->dropoff_bus_id === $busId ? "d{$s->transport_round_id}" : null,
        ]))->countBy()->max();
    }

    /** @return array<string, mixed> */
    private function roundData(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:60'], 'name_en' => ['required', 'string', 'max:60'],
            'starts_at' => ['nullable', 'date_format:H:i'], 'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
        ]);
    }

    /** @return array<string, mixed> */
    private function busData(Request $request, ?Bus $bus = null): array
    {
        $new = $bus === null;

        return $request->validate([
            'number' => [$new ? 'required' : 'sometimes', 'string', 'max:20', 'unique:buses,number'.($bus ? ",{$bus->id}" : '')],
            'driver_name' => [$new ? 'required' : 'sometimes', 'string', 'max:120'],
            'driver_phone' => ['nullable', 'string', 'max:30'],
            'capacity' => [$new ? 'required' : 'sometimes', 'integer', 'min:1', 'max:100'],
            'area_from' => [$new ? 'required' : 'sometimes', 'string', 'max:150'],
            'area_to' => [$new ? 'required' : 'sometimes', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'round_ids' => ['sometimes', 'array'], 'round_ids.*' => ['integer', 'exists:transport_rounds,id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function round(TransportRound $r, int $riders): array
    {
        return ['id' => $r->id, 'name_ar' => $r->name_ar, 'name_en' => $r->name_en, 'starts_at' => $r->starts_at ? substr((string) $r->starts_at, 0, 5) : null, 'ends_at' => $r->ends_at ? substr((string) $r->ends_at, 0, 5) : null, 'riders' => $riders];
    }

    /** @return array<string, mixed> */
    private function bus(Bus $b, $rides): array
    {
        $roundIds = $b->rounds->pluck('id')->values();

        return [
            'id' => $b->id, 'number' => $b->number, 'driver_name' => $b->driver_name, 'driver_phone' => $b->driver_phone, 'capacity' => $b->capacity,
            'area_from' => $b->area_from, 'area_to' => $b->area_to, 'notes' => $b->notes, 'is_active' => $b->is_active, 'round_ids' => $roundIds,
            // Seats taken, per round, going (pickup) and coming back (dropoff).
            'load' => $roundIds->mapWithKeys(fn ($id) => [$id => [
                'pickup' => $rides->where('transport_round_id', $id)->where('pickup_bus_id', $b->id)->count(),
                'dropoff' => $rides->where('transport_round_id', $id)->where('dropoff_bus_id', $b->id)->count(),
            ]]),
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
        $bus = fn (?Bus $b) => $b ? ['id' => $b->id, 'number' => $b->number, 'driver_name' => $b->driver_name, 'driver_phone' => $b->driver_phone, 'area_from' => $b->area_from, 'area_to' => $b->area_to] : null;

        return [
            'round' => $r->round ? $this->round($r->round, 0) : null,
            'pickup_bus' => $bus($r->pickupBus), 'dropoff_bus' => $bus($r->dropoffBus),
        ];
    }
}
