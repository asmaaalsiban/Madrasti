<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Services\SchoolDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The directorate and area printed on the school's forms (admin). */
class SchoolDetailsController extends Controller
{
    public function show(SchoolDetails $details): JsonResponse
    {
        return response()->json(['data' => $details->get()]);
    }

    public function save(Request $request, SchoolDetails $details): JsonResponse
    {
        $data = $request->validate(['directorate' => ['nullable', 'string', 'max:120'], 'district' => ['nullable', 'string', 'max:120']]);
        $details->save($data);

        return response()->json(['data' => $details->get()]);
    }
}
