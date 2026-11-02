<?php

declare(strict_types=1);

namespace App\Modules\Motivation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Motivation\Models\Badge;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The school's badges. Teachers read the active ones; the admin manages them. Deleting a badge removes it from students too. */
class BadgeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => Badge::when(! $user->hasRole(Role::Admin), fn ($q) => $q->where('is_active', true))->orderBy('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Badge::create($this->validated($request))], 201);
    }

    public function update(Request $request, Badge $badge): JsonResponse
    {
        $badge->update($this->validated($request));

        return response()->json(['data' => $badge]);
    }

    public function destroy(Badge $badge): Response
    {
        $badge->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'string', 'max:16'],
            'points_required' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
