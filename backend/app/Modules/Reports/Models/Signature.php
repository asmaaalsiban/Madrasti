<?php

declare(strict_types=1);

namespace App\Modules\Reports\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A signature image for the report card (see the migration). */
class Signature extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['scope', 'user_id', 'title', 'signer_name', 'original_path', 'image_path', 'strength', 'is_active', 'sort_order', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'strength' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The processed (transparent) image as a data URI, so a page can show and print it without another request. */
    public function dataUri(): ?string
    {
        return Storage::exists($this->image_path) ? 'data:image/png;base64,'.base64_encode(Storage::get($this->image_path)) : null;
    }
}
