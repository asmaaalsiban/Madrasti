<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyQuestion extends Model
{
    public const TYPES = ['single_choice', 'multiple_choice', 'text', 'yes_no'];

    /**
     * @var list<string>
     */
    protected $fillable = ['survey_id', 'type', 'text', 'options', 'is_required', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['options' => 'array', 'is_required' => 'boolean'];
    }
}
