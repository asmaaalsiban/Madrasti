<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyAnswer extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['survey_response_id', 'survey_question_id', 'value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
