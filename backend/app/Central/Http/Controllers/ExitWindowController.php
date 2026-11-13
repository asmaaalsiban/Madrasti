<?php

declare(strict_types=1);

namespace App\Central\Http\Controllers;

use App\Central\Jobs\PrepareExit;
use App\Central\Models\SchoolExitStep;
use App\Central\Models\Tenant;
use App\Central\Services\CentralAudit;
use App\Central\Services\ExitWindow;
use App\Central\Support\SchoolState;
use App\Central\Support\SchoolStatus;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The owner's side of a school's exit: open and extend the window, watch and retry the preparation, download the files. */
class ExitWindowController extends Controller
{
    public function open(Request $request, Tenant $tenant, ExitWindow $window): JsonResponse
    {
        $days = $this->days($request, ExitWindow::DEFAULT_DAYS);
        try {
            $window->open($tenant, $days);
        } catch (DomainException) {
            abort(422, __('exit.already_open'));
        }

        return response()->json(['data' => $this->stepRows($tenant)], 202);
    }

    public function extend(Request $request, Tenant $tenant, ExitWindow $window): JsonResponse
    {
        $days = $this->days($request);
        abort_unless(SchoolStatus::of($tenant) === SchoolState::ExitWindow, 422, __('exit.not_open'));

        $window->extend($tenant, $days);

        return response()->json(['data' => ['exit_window_ends_at' => $tenant->exit_window_ends_at]]);
    }

    public function steps(Tenant $tenant): JsonResponse
    {
        return response()->json(['data' => $this->stepRows($tenant)]);
    }

    /** @return list<array<string, mixed>> */
    private function stepRows(Tenant $tenant): array
    {
        return SchoolExitStep::where('tenant_id', $tenant->getTenantKey())->orderBy('id')->get()->map(fn (SchoolExitStep $s) => [
            'step' => $s->step,
            'status' => $s->status,
            'error' => $s->error,
            'finished_at' => $s->finished_at?->toIso8601String(),
        ])->all();
    }

    public function retry(Tenant $tenant, string $step, ExitWindow $window): JsonResponse
    {
        $rows = SchoolExitStep::where('tenant_id', $tenant->getTenantKey())->get();
        $row = $rows->firstWhere('step', $step);
        abort_if($row === null, 404);

        // Another step being prepared owns the job: retrying now could leave this one waiting with no job to run it.
        abort_if($rows->contains(fn (SchoolExitStep $s) => $s->isActive()), 422, __('exit.already_open'));

        // A failed or stale step is redone. A done step is never redone: it is only (re)queued when every step is done but the
        // window was never opened for the request (a run died right after the last step); the job then just opens it.
        $resume = $row->isDone() && $rows->every->isDone() && $tenant->exit_window_requested && SchoolStatus::of($tenant) !== SchoolState::ExitWindow;
        abort_unless($row->status === 'failed' || $row->isStale() || $resume, 422, __('exit.not_failed'));

        if (! $resume) {
            $row->update(['status' => 'pending', 'error' => null]);
        }
        $window->queue($tenant);

        return response()->json(['data' => $this->stepRows($tenant)]);
    }

    public function package(Tenant $tenant, CentralAudit $audit): StreamedResponse
    {
        return $this->download($tenant, SchoolExitStep::PACKAGE, 'package_downloaded', $audit);
    }

    public function archive(Tenant $tenant, CentralAudit $audit): StreamedResponse
    {
        return $this->download($tenant, SchoolExitStep::ARCHIVE, 'archive_downloaded', $audit);
    }

    private function download(Tenant $tenant, string $step, string $action, CentralAudit $audit): StreamedResponse
    {
        $row = SchoolExitStep::where('tenant_id', $tenant->getTenantKey())->where('step', $step)->where('status', 'done')->first();
        $disk = Storage::disk(config('backup.disk'));
        abort_if($row === null || ! $row->path || ! $disk->exists($row->path), 404, __('exit.not_ready'));

        $audit->record($action, (string) $tenant->getTenantKey());

        return $disk->download($row->path, $tenant->getTenantKey().'-'.$step.'.zip');
    }

    private function days(Request $request, ?int $default = null): int
    {
        $rules = ['integer', 'min:1', 'max:365'];
        $data = $request->validate(['days' => $default === null ? ['required', ...$rules] : ['nullable', ...$rules]]);

        return (int) ($data['days'] ?? $default);
    }
}
