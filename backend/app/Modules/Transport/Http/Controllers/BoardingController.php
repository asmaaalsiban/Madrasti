<?php

declare(strict_types=1);

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Transport\Models\Bus;
use App\Modules\Transport\Models\BusBoarding;
use App\Modules\Transport\Models\StudentTransport;
use App\Modules\Transport\Models\TransportRound;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The escort (or the driver) ticks, for each round, who boarded the bus and who did not, like taking attendance. It is for the school's
 * own record: nobody is notified. The escort can tick only today; the admin can look at any day and correct it.
 */
class BoardingController extends Controller
{
    /** The marks of a day for the signed-in driver's or escort's buses: [{bus_id, round_id, student_id, status}]. */
    public function mine(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? now()->toDateString();
        $buses = Bus::where(fn ($q) => $q->where('driver_id', $request->user()->id)->orWhereHas('rounds', fn ($r) => $r->where('bus_round.escort_id', $request->user()->id)))->pluck('id');

        return response()->json(['data' => [
            'date' => $date, 'today' => now()->toDateString(),
            'marks' => BusBoarding::where('date', $date)->whereIn('bus_id', $buses)->get(['bus_id', 'transport_round_id', 'student_id', 'status'])
                ->map(fn ($m) => ['bus_id' => $m->bus_id, 'round_id' => $m->transport_round_id, 'student_id' => $m->student_id, 'status' => $m->status])->values(),
        ]]);
    }

    /** Ticks one student ("boarded" / "not_boarded"), or clears the tick (status null). */
    public function mark(Request $request): JsonResponse
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'bus_id' => ['required', 'integer', 'exists:buses,id'], 'round_id' => ['required', 'integer', 'exists:transport_rounds,id'],
            'student_id' => ['required', 'integer', 'exists:students,id'], 'status' => ['nullable', Rule::in(['boarded', 'not_boarded'])],
        ]);
        $bus = Bus::findOrFail($d['bus_id']);
        $this->authorizeFor($request->user(), $bus, (int) $d['round_id'], $d['date']);
        if (! $this->rides((int) $bus->id, (int) $d['round_id'], (int) $d['student_id'])) {
            throw ValidationException::withMessages(['student_id' => [__('transport.not_on_this_round')]]);
        }
        $key = ['date' => $d['date'], 'bus_id' => $bus->id, 'transport_round_id' => $d['round_id'], 'student_id' => $d['student_id']];
        if (($d['status'] ?? null) === null) {
            BusBoarding::where($key)->delete();
        } else {
            BusBoarding::updateOrCreate($key, ['status' => $d['status'], 'marked_by' => $request->user()->id]);
        }

        return response()->json(['data' => ['status' => $d['status'] ?? null]]);
    }

    /** "Everyone boarded": ticks as boarded every student of the round that has no tick yet (the ones already ticked stay as they are). */
    public function markAll(Request $request): JsonResponse
    {
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'bus_id' => ['required', 'integer', 'exists:buses,id'], 'round_id' => ['required', 'integer', 'exists:transport_rounds,id']]);
        $bus = Bus::findOrFail($d['bus_id']);
        $this->authorizeFor($request->user(), $bus, (int) $d['round_id'], $d['date']);
        $round = TransportRound::findOrFail($d['round_id']);
        $done = 0;
        foreach ($this->studentIds((int) $bus->id, $round) as $studentId) {
            $mark = BusBoarding::firstOrCreate(['date' => $d['date'], 'bus_id' => $bus->id, 'transport_round_id' => $round->id, 'student_id' => $studentId], ['status' => 'boarded', 'marked_by' => $request->user()->id]);
            $done += $mark->wasRecentlyCreated ? 1 : 0;
        }

        return response()->json(['data' => ['marked' => $done]]);
    }

    /** The admin: for a day, every bus and round with how many boarded, did not board, and were not ticked, and who did not board. */
    public function report(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? now()->toDateString();
        $marks = BusBoarding::where('date', $date)->get()->groupBy(fn ($m) => $m->bus_id.'-'.$m->transport_round_id);
        $names = \App\Modules\Academic\Models\Student::whereIn('id', $marks->flatten(1)->pluck('student_id'))->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $rows = [];
        foreach (Bus::with(['rounds', 'driver:id,name'])->where('is_active', true)->orderByRaw('cast(number as unsigned), number')->get() as $bus) {
            foreach ($bus->rounds as $round) {
                $ids = $this->studentIds((int) $bus->id, $round);
                if ($ids === []) {
                    continue;
                }
                $m = $marks->get($bus->id.'-'.$round->id, collect());
                $rows[] = [
                    'bus' => ['id' => $bus->id, 'number' => $bus->number, 'escort' => $bus->escortFor((int) $round->id)?->name, 'driver' => $bus->driver?->name],
                    'round' => ['id' => $round->id, 'name_ar' => $round->name_ar, 'name_en' => $round->name_en, 'period' => $round->period],
                    'total' => count($ids), 'boarded' => $m->where('status', 'boarded')->count(), 'not_boarded' => $m->where('status', 'not_boarded')->count(), 'not_ticked' => count($ids) - $m->count(),
                    'missed' => $m->where('status', 'not_boarded')->map(fn ($x) => $names[$x->student_id]->only(['id', 'name_ar', 'name_en']))->values(),
                ];
            }
        }

        return response()->json(['data' => ['date' => $date, 'rows' => $rows]]);
    }

    /** The driver of this bus, or the escort of this round, today only; the admin, any day. */
    private function authorizeFor(User $user, Bus $bus, int $roundId, string $date): void
    {
        if ($user->hasRole(Role::Admin)) {
            return;
        }
        abort_unless($bus->driver_id === $user->id || $bus->isEscortOf($roundId, $user->id), 403);
        if ($date !== now()->toDateString()) {
            throw ValidationException::withMessages(['date' => [__('transport.only_today')]]);
        }
    }

    /** Whether this student rides this bus in this round (the morning round is the pickup, the evening round the drop-off). */
    private function rides(int $busId, int $roundId, int $studentId): bool
    {
        return StudentTransport::where('student_id', $studentId)->where(fn ($q) => $q->where(fn ($w) => $w->where('pickup_bus_id', $busId)->where('pickup_round_id', $roundId))
            ->orWhere(fn ($w) => $w->where('dropoff_bus_id', $busId)->where('dropoff_round_id', $roundId)))->exists();
    }

    /** @return list<int> */
    private function studentIds(int $busId, TransportRound $round): array
    {
        return StudentTransport::where(fn ($q) => $q->where(fn ($w) => $w->where('pickup_bus_id', $busId)->where('pickup_round_id', $round->id))
            ->orWhere(fn ($w) => $w->where('dropoff_bus_id', $busId)->where('dropoff_round_id', $round->id)))->pluck('student_id')->unique()->values()->all();
    }
}
