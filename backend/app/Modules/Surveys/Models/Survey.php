<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A poll, a survey or a parent approval (e.g. permission for a trip) sent to parents. */
class Survey extends Model
{
    public const TYPES = ['poll', 'survey', 'approval'];

    /**
     * @var list<string>
     */
    protected $fillable = ['created_by', 'type', 'title', 'description', 'opens_at', 'closes_at', 'audience', 'classroom_id', 'closed_at', 'reminded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime', 'closed_at' => 'datetime', 'reminded_at' => 'datetime'];
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
