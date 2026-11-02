<?php

declare(strict_types=1);

namespace App\Modules\Motivation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Points given to (or taken from) a student on a day. */
class PointAward extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['student_id', 'teacher_id', 'point_category_id', 'note', 'points', 'awarded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['points' => 'integer', 'awarded_at' => 'datetime'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
