<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A quick positive or negative note about something a student did on a day. */
class BehaviorNote extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['student_id', 'teacher_id', 'type', 'note', 'visible_to_parent', 'noted_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['visible_to_parent' => 'boolean', 'noted_on' => 'date:Y-m-d'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
