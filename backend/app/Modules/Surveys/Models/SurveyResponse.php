<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyResponse extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['survey_id', 'user_id', 'student_id', 'decision', 'submitted_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }
}
