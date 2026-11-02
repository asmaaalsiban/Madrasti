<?php

declare(strict_types=1);

namespace App\Modules\Motivation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Motivation\Models\PointCategory;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The categories of points. Teachers read the active ones; the admin manages them. Past awards keep their own note, so deleting is safe. */
class PointCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => PointCategory::when(! $user->hasRole(Role::Admin), fn ($q) => $q->where('is_active', true))->orderByDesc('points')->orderBy('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => PointCategory::create($this->validated($request))], 201);
    }

    public function update(Request $request, PointCategory $category): JsonResponse
    {
        $category->update($this->validated($request));

        return response()->json(['data' => $category]);
    }

    public function destroy(PointCategory $category): Response
    {
        $category->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'points' => ['required', 'integer', 'between:-20,20', 'not_in:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
