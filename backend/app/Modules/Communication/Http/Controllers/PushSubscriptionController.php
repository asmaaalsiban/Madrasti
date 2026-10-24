<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Services\PushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** A browser or phone allows (or stops) notifications for the signed-in user. */
class PushSubscriptionController extends Controller
{
    /** Whether push is available and the public key the browser needs to subscribe. */
    public function config(): JsonResponse
    {
        return response()->json(['data' => ['enabled' => PushNotifier::enabled(), 'public_key' => PushNotifier::enabled() ? config('push.public_key') : null]]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // The same browser signed in as someone else takes the subscription over.
        DB::table('push_subscriptions')->updateOrInsert(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            ['user_id' => $request->user()->id, 'endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
                'user_agent' => substr((string) $request->userAgent(), 0, 250), 'created_at' => now(), 'updated_at' => now()],
        );

        return response()->json(['data' => ['subscribed' => true]]);
    }

    public function unsubscribe(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        DB::table('push_subscriptions')->where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->noContent();
    }
}
