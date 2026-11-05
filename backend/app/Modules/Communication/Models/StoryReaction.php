<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;

class StoryReaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['story_id', 'user_id'];
}
