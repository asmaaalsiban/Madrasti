<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Communication\Services\AnnouncementAudience;
use App\Shared\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A notice from the admin (to everyone, a role or a section) or from a teacher (to a section they teach). */
class Announcement extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['author_id', 'title', 'body', 'audience', 'audience_role', 'classroom_id', 'is_pinned', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'expires_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** The announcements a user may read: not expired, and meant for them (an admin reads all; an author their own). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        if ($user->hasRole(Role::Admin)) {
            return $query;
        }
        $sections = app(AnnouncementAudience::class)->classroomIds($user);

        return $query->where(fn ($q) => $q
            ->where('audience', 'all')
            ->orWhere(fn ($r) => $r->where('audience', 'role')->where('audience_role', $user->role->value))
            ->orWhere(fn ($r) => $r->where('audience', 'classroom')->whereIn('classroom_id', $sections))
            ->orWhere('author_id', $user->id));
    }
}
