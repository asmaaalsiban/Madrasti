<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** A private photo of a story (opened only by people who may see the story). */
class StoryMedia extends Model
{
    protected $table = 'story_media';

    /**
     * @var list<string>
     */
    protected $fillable = ['story_id', 'path', 'position'];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @param  array<int, UploadedFile>  $uploads */
    public static function addTo(Story $story, array $uploads): void
    {
        foreach (array_values($uploads) as $i => $upload) {
            self::create(['story_id' => $story->id, 'path' => $upload->store('stories'), 'position' => $i + 1]);
        }
    }

    /** Deletes the files from the disk (the rows go with their story). */
    public static function forget(Collection $media): void
    {
        $media->each(fn (self $m) => Storage::delete($m->path));
    }
}
