<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Models;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One parent (and, for an approval, one child) who has to answer. */
class SurveyRecipient extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['survey_id', 'user_id', 'student_id'];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
