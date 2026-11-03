<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The PayTabs account of THIS school (every school has its own, so the money goes to the school's own bank account). Kept in the school's
 * own settings; the server key is stored encrypted and is never sent back to any screen. A school without its own account falls back to the
 * keys in .env (the platform's account) when PAYMENT_GATEWAY=paytabs.
 */
class PayTabsSettings
{
    public const REGIONS = [
        'jordan' => 'https://secure-jordan.paytabs.com', 'saudi' => 'https://secure.paytabs.sa', 'uae' => 'https://secure.paytabs.com',
        'egypt' => 'https://secure-egypt.paytabs.com', 'oman' => 'https://secure-oman.paytabs.com', 'global' => 'https://secure-global.paytabs.com',
    ];

    /** @return array{profile_id: string, server_key: string, base_url: string, enabled: bool}|null this school's own account, or null */
    public function own(): ?array
    {
        $raw = DB::table('school_settings')->where('key', 'paytabs')->value('value');
        $data = $raw ? json_decode((string) $raw, true) : null;
        if (! is_array($data) || empty($data['profile_id']) || empty($data['server_key'])) {
            return null;
        }
        try {
            $key = Crypt::decryptString($data['server_key']);
        } catch (\Throwable) {
            return null; // unreadable (the app key changed): treated as not set
        }

        return ['profile_id' => (string) $data['profile_id'], 'server_key' => $key, 'base_url' => (string) ($data['base_url'] ?? self::REGIONS['jordan']), 'enabled' => (bool) ($data['enabled'] ?? false)];
    }

    /** The account to use: the school's own when it is switched on, else the platform's from .env. @return array{profile_id: string, server_key: string, base_url: string}|null */
    public function effective(): ?array
    {
        $own = $this->own();
        if ($own !== null && $own['enabled']) {
            return $own;
        }
        $env = (array) config('payments.providers.paytabs');

        return ! empty($env['profile_id']) && ! empty($env['server_key']) && config('payments.driver') === 'paytabs'
            ? ['profile_id' => (string) $env['profile_id'], 'server_key' => (string) $env['server_key'], 'base_url' => (string) ($env['base_url'] ?? self::REGIONS['jordan'])]
            : null;
    }

    /** Is this school paying through its own account? */
    public function ownEnabled(): bool
    {
        return ($this->own()['enabled'] ?? false) === true;
    }

    /**
     * @param  array{profile_id: string, server_key?: string|null, base_url: string, enabled: bool}  $data  an empty server_key keeps the saved one
     */
    public function save(array $data): void
    {
        $old = $this->own();
        $key = ($data['server_key'] ?? '') !== '' ? $data['server_key'] : ($old['server_key'] ?? '');
        DB::table('school_settings')->updateOrInsert(['key' => 'paytabs'], [
            'value' => json_encode(['profile_id' => $data['profile_id'], 'server_key' => $key === '' ? '' : Crypt::encryptString($key), 'base_url' => $data['base_url'], 'enabled' => $data['enabled'] && $key !== '']),
            'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    /** What a screen may see: never the key itself, only that one is saved and its last four characters. @return array<string, mixed> */
    public function present(): array
    {
        $own = $this->own();

        return [
            'profile_id' => $own['profile_id'] ?? '', 'base_url' => $own['base_url'] ?? self::REGIONS['jordan'], 'enabled' => $own['enabled'] ?? false,
            'server_key_set' => $own !== null, 'server_key_hint' => $own ? '••••'.substr($own['server_key'], -4) : null,
            'regions' => self::REGIONS, 'using_platform_account' => $own === null || ! $own['enabled'] ? $this->effective() !== null : false,
        ];
    }
}
