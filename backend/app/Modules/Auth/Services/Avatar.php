<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A person's picture. `users.avatar_path` holds one of two things: `preset:a07` (a ready-made character, drawn by the frontend from
 * /avatars/a07.svg) or the path of an uploaded photo on the private disk. Nothing in it means "initials". The photo is cut to a square and scaled by the
 * browser (PhotoCropper), so what is stored is a small JPEG without camera data.
 */
final class Avatar
{
    public const PRESET_PATTERN = '/^a(0[1-9]|1[0-9]|2[0-4])$/'; // a01 ... a24

    /** What the app needs to draw it: null, or ['type' => 'preset', 'key'] or ['type' => 'photo', 'url', 'v'] (v changes with the picture). */
    public static function for(User $user): ?array
    {
        $path = $user->avatar_path;
        if (blank($path)) {
            return null;
        }
        if (str_starts_with($path, 'preset:')) {
            return ['type' => 'preset', 'key' => substr($path, 7)];
        }

        return ['type' => 'photo', 'url' => "/users/{$user->id}/avatar", 'v' => substr(md5($path), 0, 8)];
    }

    public function setPreset(User $user, string $key): void
    {
        $this->deletePhoto($user);
        $user->update(['avatar_path' => "preset:$key"]);
    }

    /** Stores the picture as it is: the browser has already cut it to a square JPEG (the controller checked that), so nothing here needs GD. */
    public function setPhoto(User $user, UploadedFile $file): void
    {
        $path = 'avatars/'.$user->id.'-'.Str::random(12).'.jpg';
        Storage::put($path, (string) file_get_contents($file->getRealPath()));
        $this->deletePhoto($user);
        $user->update(['avatar_path' => $path]);
    }

    public function remove(User $user): void
    {
        $this->deletePhoto($user);
        $user->update(['avatar_path' => null]);
    }

    private function deletePhoto(User $user): void
    {
        $old = $user->avatar_path;
        if (filled($old) && ! str_starts_with($old, 'preset:')) {
            Storage::delete($old);
        }
    }
}
