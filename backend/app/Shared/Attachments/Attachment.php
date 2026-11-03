<?php

declare(strict_types=1);

namespace App\Shared\Attachments;

use App\Models\User;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** A private file (image, PDF, Office document...) attached to a task, a task hand-in or a survey. */
class Attachment extends Model
{
    public const MAX_PER_OWNER = 10;

    public const MAX_KB = 10240;

    public const MIMES = 'jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv';

    /** What each owner type in a URL means. */
    public const OWNERS = ['task' => Task::class, 'task_part' => TaskAssignee::class, 'survey' => Survey::class];

    protected $fillable = ['owner_type', 'owner_id', 'uploaded_by', 'path', 'original_name', 'mime', 'size'];

    /** @param  array<int, UploadedFile>  $uploads */
    public static function addTo(string $type, Model $owner, array $uploads, User $by): Collection
    {
        return collect($uploads)->map(fn (UploadedFile $f) => self::create([
            'owner_type' => $type, 'owner_id' => $owner->getKey(), 'uploaded_by' => $by->id, 'path' => $f->store("attachments/$type"),
            'original_name' => mb_substr($f->getClientOriginalName(), 0, 200), 'mime' => $f->getMimeType(), 'size' => $f->getSize(),
        ]));
    }

    /** Removes every file of an owner (rows and disk) — called when the owner is deleted. */
    public static function purge(string $type, int|string $id): void
    {
        $files = self::where('owner_type', $type)->where('owner_id', $id)->get();
        $files->each(fn (self $f) => Storage::delete($f->path));
        self::where('owner_type', $type)->where('owner_id', $id)->delete();
    }

    /** @return Collection<int, self> the files of many owners of one type, grouped by owner id */
    public static function groupedFor(string $type, iterable $ids): Collection
    {
        return self::where('owner_type', $type)->whereIn('owner_id', collect($ids)->all())->orderBy('id')->get()->groupBy('owner_id');
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['id' => $this->id, 'name' => $this->original_name, 'mime' => $this->mime, 'size' => (int) $this->size, 'is_image' => str_starts_with((string) $this->mime, 'image/')];
    }

    /** @param  Collection<int, self>|null  $files */
    public static function list(?Collection $files): array
    {
        return ($files ?? collect())->map->present()->values()->all();
    }
}
