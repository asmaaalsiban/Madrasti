<?php

declare(strict_types=1);

namespace App\Central\Http\Resources;

use App\Central\Models\Tenant;
use App\Central\Support\SchoolCounts;
use App\Central\Support\SchoolStatus;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Backup\BackupService;
use App\Shared\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $counts = $this->counts();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'primary_color' => $this->primary_color,
            'default_locale' => $this->default_locale,
            'is_active' => $this->is_active,
            'state' => SchoolStatus::of($this->resource)->value,
            'subscription_ends_at' => $this->subscription_ends_at,
            'max_students' => $this->max_students,
            'students_count' => $counts['students'],
            'staff_count' => $counts['staff'],
            'last_backup_at' => (app(BackupService::class)->list($this->id)[0]['at'] ?? null)?->toIso8601String(),
            'exit_window_ends_at' => $this->exit_window_ends_at,
            'closed_at' => $this->closed_at,
            'domains' => $this->domains->pluck('domain'),
        ];
    }

    /**
     * Read from the school's own database (the only place its people are), and cached together for a few minutes (and dropped at once when the
     * school adds or removes a student or staff member, see SchoolCounts) so a list of schools does not open every database on every request.
     *
     * @return array{students: int, staff: int}
     */
    private function counts(): array
    {
        return Cache::remember(SchoolCounts::key((string) $this->id), now()->addMinutes(SchoolCounts::MINUTES), fn () => $this->resource->run(fn () => [
            // The same number the student limit counts, so "used / limit" always agrees with what the school is allowed.
            'students' => Student::count(),
            'staff' => User::whereIn('role', [Role::Admin, Role::Teacher])->count(),
        ]));
    }
}
