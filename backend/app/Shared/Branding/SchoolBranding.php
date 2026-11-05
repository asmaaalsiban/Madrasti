<?php

declare(strict_types=1);

namespace App\Shared\Branding;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The school's own look: its logo and the two colours the browser read from it (the main colour and a dark one).
 * Kept in the school's own settings, so no school ever sees another's. Without a logo the site keeps its default look.
 */
class SchoolBranding
{
    public const KEY = 'branding';

    public const MAX_KB = 2048;

    /** @return array{logo: ?string, mime: ?string, version: ?int, primary: ?string, dark: ?string} */
    public function get(): array
    {
        $raw = DB::table('school_settings')->where('key', self::KEY)->value('value');
        $d = $raw ? (json_decode((string) $raw, true) ?: []) : [];

        return ['logo' => $d['logo'] ?? null, 'mime' => $d['mime'] ?? null, 'version' => $d['version'] ?? null, 'primary' => $d['primary'] ?? null, 'dark' => $d['dark'] ?? null];
    }

    /** What the public "which school is this?" answer adds: the logo address and the colours (null = the default look). */
    public function publicData(): array
    {
        $b = $this->get();

        return ['logo_url' => $b['logo'] ? '/api/school/logo?v='.$b['version'] : null, 'primary_color' => $b['primary'], 'dark_color' => $b['dark']];
    }

    /** Keeps the logo (a new one replaces the old) and/or the colours. */
    public function save(?UploadedFile $logo, ?string $primary, ?string $dark): void
    {
        $b = $this->get();
        if ($logo !== null) {
            $b['logo'] && Storage::delete($b['logo']);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$logo->getMimeType()] ?? 'png';
            $b['logo'] = $logo->storeAs('branding', 'logo-'.bin2hex(random_bytes(6)).'.'.$ext);
            $b['mime'] = $logo->getMimeType();
            $b['version'] = time();
        }
        $b['primary'] = $primary ?? $b['primary'];
        $b['dark'] = $dark ?? $b['dark'];
        $this->write($b);
    }

    /** Back to the default look: the logo file and the colours go. */
    public function clear(): void
    {
        $b = $this->get();
        $b['logo'] && Storage::delete($b['logo']);
        DB::table('school_settings')->where('key', self::KEY)->delete();
    }

    /** @param  array<string, mixed>  $b */
    private function write(array $b): void
    {
        DB::table('school_settings')->updateOrInsert(['key' => self::KEY], ['value' => json_encode($b), 'updated_at' => now(), 'created_at' => now()]);
    }
}
