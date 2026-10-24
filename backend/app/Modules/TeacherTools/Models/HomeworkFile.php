<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** A private photo of a homework or of a student's answer. */
class HomeworkFile extends Model
{
    public const MAX_PER_OWNER = 10;

    /**
     * @var list<string>
     */
    protected $fillable = ['homework_assignment_id', 'homework_submission_id', 'path', 'position'];

    public function homework(): BelongsTo
    {
        return $this->belongsTo(HomeworkAssignment::class, 'homework_assignment_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(HomeworkSubmission::class, 'homework_submission_id');
    }

    /**
     * Adds photos after the existing ones (never replacing them) and returns how many were added.
     *
     * @param  array<int, UploadedFile>  $uploads
     * @param  array<string, int>  $owner  e.g. ['homework_assignment_id' => 5]
     */
    public static function addTo(array $owner, array $uploads, string $directory): int
    {
        $next = (int) self::where($owner)->max('position') + 1;
        foreach (array_values($uploads) as $i => $upload) {
            self::create($owner + ['path' => $upload->store($directory), 'position' => $next + $i]);
        }

        return count($uploads);
    }

    /** Deletes the files from the disk (the rows go with their owner or are deleted by the caller). */
    public static function forget(Collection $files): void
    {
        $files->each(fn (self $f) => Storage::delete($f->path));
    }
}
