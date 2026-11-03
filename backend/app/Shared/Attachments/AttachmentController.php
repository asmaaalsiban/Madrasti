<?php

declare(strict_types=1);

namespace App\Shared\Attachments;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Upload, open and remove the files of tasks, hand-ins and surveys. */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentAccess $access) {}

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $owner = $this->owner($type, $id);
        abort_unless($this->access->manages($this->user($request), $owner), 403, __('tasks.not_allowed'));
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.Attachment::MAX_PER_OWNER],
            'files.*' => ['file', 'mimes:'.Attachment::MIMES, 'max:'.Attachment::MAX_KB],
        ]);
        if (Attachment::where('owner_type', $type)->where('owner_id', $id)->count() + count($data['files']) > Attachment::MAX_PER_OWNER) {
            throw ValidationException::withMessages(['files' => [__('attachments.too_many', ['max' => Attachment::MAX_PER_OWNER])]]);
        }
        $added = Attachment::addTo($type, $owner, $request->file('files'), $this->user($request));

        return response()->json(['data' => $added->map->present()->values()], 201);
    }

    /** Images open in the page; other files download with their own name. */
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($this->access->views($this->user($request), $this->owner($attachment->owner_type, (int) $attachment->owner_id)) && Storage::exists($attachment->path), 404);
        $image = str_starts_with((string) $attachment->mime, 'image/');

        return Storage::response($attachment->path, $attachment->original_name, $attachment->mime ? ['Content-Type' => $attachment->mime] : [], $image ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, Attachment $attachment): Response
    {
        abort_unless($this->access->manages($this->user($request), $this->owner($attachment->owner_type, (int) $attachment->owner_id)), 403);
        Storage::delete($attachment->path);
        $attachment->delete();

        return response()->noContent();
    }

    private function owner(string $type, int $id): Model
    {
        $class = Attachment::OWNERS[$type] ?? null;
        abort_if($class === null, 404);

        return $class::findOrFail($id);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
