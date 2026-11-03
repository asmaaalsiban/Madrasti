<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Models;

use App\Models\User;
use App\Shared\Attachments\Attachment;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A task one member of management (or a head of department) gives to one or more teachers. */
class Task extends Model
{
    use Auditable;

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = ['created_by', 'title', 'description', 'due_on', 'priority'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        // The files of a task and of its hand-ins go with it.
        static::deleting(function (Task $task): void {
            $task->assignees()->get()->each->delete();
            Attachment::purge('task', $task->id);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TaskAssignee, $this> */
    public function assignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }
}
