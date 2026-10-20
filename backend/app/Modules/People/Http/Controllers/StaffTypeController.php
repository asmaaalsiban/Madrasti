<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Enums\StaffType;
use Illuminate\Http\JsonResponse;

/**
 * The staff types and what each one needs, so the forms do not repeat the rules.
 */
class StaffTypeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (StaffType $type) => [
            'value' => $type->value,
            'category' => $type->category(),
            'needs_subject' => $type->needsSubject(),
            'needs_floor' => $type->needsFloor(),
            'grants_admin_access' => $type->grantsAdminAccess(),
            'can_be_homeroom' => $type->canBeHomeroom(),
        ], StaffType::cases())]);
    }
}
