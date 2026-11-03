<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Models;

use App\Models\User;
use App\Shared\Attachments\Attachment;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's part of a task: their own status and note. */
class TaskAssignee extends Model
{
    use Auditable;

    public const STATUSES = ['todo', 'doing', 'done'];

    protected $fillable = ['task_id', 'user_id', 'status', 'note', 'done_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (TaskAssignee $part) => Attachment::purge('task_part', $part->id));
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
