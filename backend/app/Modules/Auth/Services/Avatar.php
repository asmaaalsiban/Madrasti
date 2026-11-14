<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A person's picture. `users.avatar_path` holds one of two things: `preset:a07` (a ready-made character, drawn by the frontend from
 * /avatars/a07.svg) or the path of an uploaded photo on the private disk. Nothing in it means "initials". A photo is cut to a square and
 * scaled to 256 px on the server, so what is stored is small and has no camera data.
 */
final class Avatar
{
    public const PRESET_PATTERN = '/^a(0[1-9]|1[0-9]|2[0-4])$/'; // a01 ... a24

    public const SIZE = 256;

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

    public function setPhoto(User $user, UploadedFile $file): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw new RuntimeException('Not a picture.');
        }
        $w = imagesx($source);
        $h = imagesy($source);
        $side = min($w, $h);

        // The middle square, scaled to SIZE, on white (a transparent PNG would turn black as a JPEG).
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagejpeg($canvas, null, 86);
        $bytes = (string) ob_get_clean();

        $path = 'avatars/'.$user->id.'-'.Str::random(12).'.jpg';
        Storage::put($path, $bytes);
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
