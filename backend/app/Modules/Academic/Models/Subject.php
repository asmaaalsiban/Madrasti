<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Shared\Traits\Auditable;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use Auditable, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['code', 'name_ar', 'name_en'];

    protected static function newFactory(): SubjectFactory
    {
        return SubjectFactory::new();
    }
}
