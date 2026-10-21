<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;

/** One lesson slot of the school week: a day and a number with its times. Every section shares the same bells. */
class Period extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['day_of_week', 'number', 'starts_at', 'ends_at', 'is_break'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'number' => 'integer', 'is_break' => 'boolean'];
    }
}
