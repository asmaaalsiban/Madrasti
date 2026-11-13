<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Central\Models\SchoolExitStep;
use App\Central\Models\Tenant;
use App\Central\Services\CentralAudit;
use App\Central\Support\SchoolState;
use App\Central\Support\SchoolStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** What a school administrator sees and takes during the school's exit window (administrators only, see the routes). */
class ExitExportController extends Controller
{
    public function show(): JsonResponse
    {
        $school = $this->school();
        $ends = $school->exit_window_ends_at;

        return response()->json([
            'ends_at' => $ends->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($ends, false)),
            'ready' => $this->packagePath($school) !== null,
        ]);
    }

    public function package(Request $request, CentralAudit $audit): StreamedResponse
    {
        $school = $this->school();
        $path = $this->packagePath($school);
        abort_if($path === null, 404);

        $audit->record('package_downloaded', (string) $school->getTenantKey(), ['by' => $request->user()->getKey()]);

        return Storage::disk(config('backup.disk'))->download($path, $school->getTenantKey().'-handover.zip');
    }

    /** The school whose window is open now; 404 when there is none. */
    private function school(): Tenant
    {
        $school = tenant();
        abort_unless($school instanceof Tenant && SchoolStatus::of($school) === SchoolState::ExitWindow, 404);

        return $school;
    }

    /** The package file of the school, or null when it is not stored. */
    private function packagePath(Tenant $school): ?string
    {
        $path = SchoolExitStep::where('tenant_id', $school->getTenantKey())->where('step', SchoolExitStep::PACKAGE)->where('status', 'done')->value('path');

        return $path && Storage::disk(config('backup.disk'))->exists($path) ? $path : null;
    }
}
