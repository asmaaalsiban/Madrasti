<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'gender'];

    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class)->orderBy('level');
    }
}
