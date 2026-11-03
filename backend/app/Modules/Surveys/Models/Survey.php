<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Shared\Attachments\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A poll, a survey or a parent approval (e.g. permission for a trip) sent to parents. */
class Survey extends Model
{
    public const TYPES = ['poll', 'survey', 'approval'];

    public const RESPONDENTS = ['parents', 'students', 'both', 'staff'];

    /**
     * @var list<string>
     */
    protected $fillable = ['created_by', 'type', 'title', 'description', 'opens_at', 'closes_at', 'audience', 'classroom_id', 'grade_from_id', 'grade_to_id', 'respondents', 'closed_at', 'reminded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime', 'closed_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (Survey $survey) => Attachment::purge('survey', $survey->id));
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(SurveyRecipient::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    /** The hand-picked sections (audience "classrooms"). */
    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'survey_classrooms');
    }

    public function gradeFrom(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'grade_from_id');
    }

    public function gradeTo(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'grade_to_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Parents can answer until it is closed by hand or its closing time passes. */
    public function isOpen(): bool
    {
        return $this->closed_at === null && ($this->closes_at === null || $this->closes_at->isFuture());
    }
}
