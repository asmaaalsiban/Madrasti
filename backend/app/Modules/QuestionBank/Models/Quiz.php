<?php

declare(strict_types=1);

namespace App\Modules\QuestionBank\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A quiz a student made for themselves from the bank. */
class Quiz extends Model
{
    /** @var list<string> */
    protected $fillable = ['student_id', 'subject_id', 'question_count', 'finished_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position');
    }
}
