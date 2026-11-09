<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How a person arranged their own screens: the order of the groups and of the items (menu entries, home tiles) on a surface, the size of the
 * icons (`small`, `medium`, `large`), the shortcuts of the principal's overview (`admin_shortcuts`: `items` are the menu paths, in order) and a colour (#rrggbb) for any icon.
 * `order: null` puts a surface back to the default. Only the person themselves can change it.
 */
class LayoutController extends Controller
{
    public const SURFACES = ['admin_nav', 'admin_shortcuts', 'teacher_home', 'parent_home', 'student_home'];

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'surface' => ['required', 'in:'.implode(',', self::SURFACES)],
            'order' => ['nullable', 'array'],
            'order.groups' => ['sometimes', 'array', 'max:30'], 'order.groups.*' => ['string', 'max:80'],
            'order.items' => ['sometimes', 'array', 'max:120'], 'order.items.*' => ['string', 'max:120'],
            'order.size' => ['sometimes', 'nullable', 'in:small,medium,large'],
            'order.colors' => ['sometimes', 'nullable', 'array', 'max:120'], 'order.colors.*' => ['regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $layout = $user->layout ?? [];
        if ($data['order'] === null) {
            unset($layout[$data['surface']]);
        } else {
            $order = $data['order'];
            $layout[$data['surface']] = array_filter([
                'groups' => array_values(array_unique($order['groups'] ?? [])), 'items' => array_values(array_unique($order['items'] ?? [])),
                'size' => $order['size'] ?? null, 'colors' => ($order['colors'] ?? []) === [] ? null : array_map('strtolower', $order['colors']),
            ], fn ($v) => $v !== null);
        }
        $user->forceFill(['layout' => $layout === [] ? null : $layout])->save();

        return response()->json(['data' => (object) ($user->layout ?? [])]);
    }
}
