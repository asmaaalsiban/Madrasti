<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How a person arranged their own screens: the order of the groups and of the items (menu entries, home tiles) on a surface.
 * `order: null` puts a surface back to the default. Only the person themselves can change it.
 */
class LayoutController extends Controller
{
    public const SURFACES = ['admin_nav', 'teacher_home', 'parent_home', 'student_home'];

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'surface' => ['required', 'in:'.implode(',', self::SURFACES)],
            'order' => ['nullable', 'array'],
            'order.groups' => ['sometimes', 'array', 'max:30'], 'order.groups.*' => ['string', 'max:80'],
            'order.items' => ['sometimes', 'array', 'max:120'], 'order.items.*' => ['string', 'max:120'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $layout = $user->layout ?? [];
        if ($data['order'] === null) {
            unset($layout[$data['surface']]);
        } else {
            $layout[$data['surface']] = ['groups' => array_values(array_unique($data['order']['groups'] ?? [])), 'items' => array_values(array_unique($data['order']['items'] ?? []))];
        }
        $user->forceFill(['layout' => $layout === [] ? null : $layout])->save();

        return response()->json(['data' => (object) ($user->layout ?? [])]);
    }
}
