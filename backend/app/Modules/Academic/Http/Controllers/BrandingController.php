<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Branding\SchoolBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The principal's school look: the logo and the colours the browser read from it. Everything else on the site stays as it is.
 * The logo is served from a public address (it is shown before anybody signs in); only png, jpg and webp are accepted (never SVG).
 */
class BrandingController extends Controller
{
    public function __construct(private readonly SchoolBranding $branding) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->branding->publicData()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'logo' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:'.SchoolBranding::MAX_KB, 'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000'],
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'dark_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $this->branding->save($request->file('logo'), $data['primary_color'] ?? null, $data['dark_color'] ?? null);

        return response()->json(['data' => $this->branding->publicData()]);
    }

    public function destroy(): Response
    {
        $this->branding->clear();

        return response()->noContent();
    }

    /** The logo itself, for everybody (the sign-in page shows it). The address carries a version, so it can be cached for a long time. */
    public function logo(): StreamedResponse|Response
    {
        $b = $this->branding->get();
        abort_if(! $b['logo'] || ! Storage::exists($b['logo']), 404);

        return Storage::response($b['logo'], null, ['Content-Type' => $b['mime'] ?? 'image/png', 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }
}
