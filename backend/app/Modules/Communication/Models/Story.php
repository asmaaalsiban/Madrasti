<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A post in the class feed: news and photos for one class, one grade or the whole school. */
class Story extends Model
{
    public const AUDIENCES = ['classroom', 'grade', 'school'];

    public const MAX_PHOTOS = 6;

    /**
     * @var list<string>
     */
    protected $fillable = ['author_id', 'audience', 'classroom_id', 'grade_level_id', 'body', 'is_pinned', 'comments_enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'comments_enabled' => 'boolean'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(StoryMedia::class)->orderBy('position');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(StoryReaction::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(StoryComment::class)->orderBy('id');
    }
}
