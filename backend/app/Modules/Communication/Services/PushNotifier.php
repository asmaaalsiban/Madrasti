<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Models\User;
use App\Shared\Push\PushTransport;
use Illuminate\Support\Facades\DB;

/**
 * Sends the same events as the bell to the phones / browsers that allowed notifications. The sentence is written in the
 * language of each person. Off when no keys are configured; a failure never breaks the action that caused it.
 */
class PushNotifier
{
    public function __construct(private readonly PushTransport $transport) {}

    public static function enabled(): bool
    {
        return filled(config('push.public_key')) && filled(config('push.private_key'));
    }

    /**
     * @param  list<array{user: int, kind: string, params: array<string, mixed>, link: string}>  $events
     */
    public function send(array $events): void
    {
        if (! self::enabled() || $events === []) {
            return;
        }
        try {
            $subs = DB::table('push_subscriptions')->whereIn('user_id', array_unique(array_column($events, 'user')))->get()->groupBy('user_id');
            if ($subs->isEmpty()) {
                return;
            }
            $locales = User::whereIn('id', $subs->keys())->pluck('locale', 'id');
            $title = (string) (tenant('name') ?? config('app.name'));

            foreach ($events as $event) {
                $rows = $subs->get($event['user']);
                if ($rows === null) {
                    continue;
                }
                $locale = in_array($locales[$event['user']] ?? null, ['ar', 'en'], true) ? $locales[$event['user']] : 'ar';
                $gone = $this->transport->send(
                    $rows->map(fn ($r) => ['endpoint' => $r->endpoint, 'p256dh' => $r->p256dh, 'auth' => $r->auth])->all(),
                    ['title' => $title, 'body' => $this->text($event, $locale), 'url' => $event['link']],
                );
                if ($gone !== []) {
                    DB::table('push_subscriptions')->whereIn('endpoint_hash', array_map(fn ($e) => hash('sha256', $e), $gone))->delete();
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param  array{kind: string, params: array<string, mixed>}  $event */
    private function text(array $event, string $locale): string
    {
        $p = $event['params'];
        $pick = fn (string $key) => $p["{$key}_{$locale}"] ?? $p["{$key}_ar"] ?? '';
        $key = 'push.'.$event['kind'].($event['kind'] === 'plan_reviewed' ? '.'.($p['decision'] ?? 'approve') : '');
        $text = __($key, ['subject' => $pick('subject'), 'title' => $p['title'] ?? '', 'teacher' => $p['teacher'] ?? '', 'student' => $pick('student'), 'note' => $p['note'] ?? ''], $locale);
        if ($text === $key) {
            $text = __('push.unknown', [], $locale);
        }
        $child = $pick('child');

        return $child !== '' ? "$text ($child)" : $text;
    }
}
