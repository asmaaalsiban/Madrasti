<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use Illuminate\Database\Eloquent\Model;

class BehaviorEvaluationScore extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['behavior_evaluation_id', 'behavior_criterion_id', 'score'];
}
