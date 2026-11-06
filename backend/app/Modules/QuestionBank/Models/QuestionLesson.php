<?php

declare(strict_types=1);

namespace App\Modules\QuestionBank\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A lesson of a subject in a grade; questions hang under it. */
class QuestionLesson extends Model
{
    /** @var list<string> */
    protected $fillable = ['grade_level_id', 'subject_id', 'title', 'created_by'];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
