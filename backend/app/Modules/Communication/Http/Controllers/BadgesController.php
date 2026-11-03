<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Surveys\Http\Controllers\MySurveyController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The little counters at the top of every screen (unread notifications, announcements and chat messages, surveys waiting), in ONE answer.
 * The app asks for them every 30 seconds from every open tab, so one request instead of four is half of the server's idle work.
 * Each number comes from the same code as its own endpoint, so they always agree.
 */
class BadgesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $count = fn (JsonResponse $r): int => (int) $r->getData(true)['data']['count'];

        return response()->json(['data' => [
            'notifications' => $count(app(NotificationController::class)->unread($request)),
            'announcements' => $count(app(AnnouncementController::class)->unread($request)),
            'chat' => $count(app(ChatController::class)->unreadCount($request)),
            'surveys' => $count(app(MySurveyController::class)->pending($request)),
        ]]);
    }
}
