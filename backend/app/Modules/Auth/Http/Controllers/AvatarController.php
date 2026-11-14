<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\Avatar;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person's own picture: an uploaded photo or a ready-made character. Students choose a character only (no real photos of children
 * on the platform); everybody else may also upload a photo.
 */
class AvatarController extends Controller
{
    public function upload(Request $request, Avatar $avatar): JsonResponse
    {
        abort_if($request->user()->role === Role::Student, 403, __('avatar.students_choose'));
        // The browser sends the cut picture: a square JPEG (checked by its real content, not its name), 64-1024 px.
        $request->validate(['photo' => ['required', 'file', 'max:1024', function ($attribute, $file, $fail) {
            $info = @getimagesize($file->getRealPath());
            if (! $info || $info[2] !== IMAGETYPE_JPEG || $info[0] !== $info[1] || $info[0] < 64 || $info[0] > 1024) {
                $fail(__('avatar.bad_photo'));
            }
        }]]);

        $avatar->setPhoto($request->user(), $request->file('photo'));

        return $this->me($request);
    }

    public function preset(Request $request, Avatar $avatar): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'regex:'.Avatar::PRESET_PATTERN]]);

        $avatar->setPreset($request->user(), $data['key']);

        return $this->me($request);
    }

    public function destroy(Request $request, Avatar $avatar): JsonResponse
    {
        $avatar->remove($request->user());

        return $this->me($request);
    }

    /** The photo of a person of this school (any signed-in person of the school may see it: it is shown next to their name). */
    public function show(User $user): Response
    {
        $path = $user->avatar_path;
        abort_if(blank($path) || str_starts_with($path, 'preset:') || ! Storage::exists($path), 404);

        return Storage::response($path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }

    private function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user()->fresh())]);
    }
}
