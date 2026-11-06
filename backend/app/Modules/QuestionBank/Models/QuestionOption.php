<?php

declare(strict_types=1);

namespace App\Modules\QuestionBank\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionOption extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['question_id', 'body', 'is_correct', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }
}
