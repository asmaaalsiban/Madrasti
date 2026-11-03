<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\People\Models\StaffProfile;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What each member of staff sees. The principal switches screens on or off per person (or for several people at once); a hidden
 * screen disappears from the person's home page and menus and its address shows "not available". Students and parents are not affected.
 */
class StaffVisibilityController extends Controller
{
    public function index(): JsonResponse
    {
        $staff = User::where('role', Role::Teacher)->where('is_active', true)->with('staffProfile')->orderBy('name')->get();

        return response()->json([
            'features' => StaffProfile::FEATURES,
            'data' => $staff->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'staff_type' => $u->staffProfile?->staff_type?->value, 'hidden' => $u->staffProfile?->hidden_features ?? []])->values(),
        ]);
    }

    /** Sets the hidden screens of the chosen people (replacing what they had). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'], 'user_ids.*' => ['integer', 'exists:users,id'],
            'hidden' => ['present', 'array'], 'hidden.*' => ['string', 'in:'.implode(',', StaffProfile::FEATURES)],
        ]);
        $hidden = array_values(array_unique($data['hidden']));
        $profiles = StaffProfile::whereIn('user_id', $data['user_ids'])->whereHas('user', fn ($q) => $q->where('role', Role::Teacher))->get();
        foreach ($profiles as $profile) {
            $profile->update(['hidden_features' => $hidden === [] ? null : $hidden]);
        }

        return response()->json(['updated' => $profiles->count()]);
    }
}
