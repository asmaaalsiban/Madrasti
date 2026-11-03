<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request to delete or correct personal data, decided by the school. */
class DataRequest extends Model
{
    use Auditable;

    protected $fillable = ['user_id', 'student_id', 'type', 'status', 'message', 'answer', 'handled_by', 'handled_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
