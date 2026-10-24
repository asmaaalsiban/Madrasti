<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** The bell of the signed-in user: the latest notifications, the unread counter, mark as read. */
class NotificationController extends Controller
{
    private const LIMIT = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $user->notifications()->latest()->limit(self::LIMIT)->get();

        return response()->json(['data' => [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $rows->map(fn (DatabaseNotification $n) => [
                'id' => $n->id, 'kind' => $n->data['kind'] ?? 'unknown', 'params' => $n->data['params'] ?? [], 'link' => $n->data['link'] ?? null,
                'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['data' => ['count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['data' => ['read' => true]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => 0]]);
    }
}
