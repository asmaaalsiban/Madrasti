<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A bonus or deduction rule the school defines: a name, an amount (fixed or a percent of the salary) and an optional category. */
class PayrollTemplate extends Model
{
    use Auditable;

    public const CATEGORIES = ['private_lessons', 'summer_club', 'club', 'other'];

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'kind', 'type', 'value', 'category', 'is_active', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
