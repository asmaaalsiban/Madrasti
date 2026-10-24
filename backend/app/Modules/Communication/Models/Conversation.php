<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A chat: between two people (direct) or a section group. */
class Conversation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['type', 'title', 'classroom_id', 'include_parents', 'members_can_post', 'created_by', 'last_message_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['include_parents' => 'boolean', 'members_can_post' => 'boolean', 'last_message_at' => 'datetime'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')->withPivot('last_read_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
