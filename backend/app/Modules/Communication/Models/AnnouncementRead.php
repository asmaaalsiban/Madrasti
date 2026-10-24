<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;

class AnnouncementRead extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['announcement_id', 'user_id', 'read_at'];

    protected $table = 'announcement_reads';
}
