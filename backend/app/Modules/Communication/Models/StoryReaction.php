<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryReaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['story_id', 'user_id'];
}
