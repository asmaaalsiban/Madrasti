<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Reports\Models\Signature;
use App\Modules\Reports\Services\SignatureImage;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signatures for the report card. The admin manages the school's (principal...) and anyone's personal one; a teacher
 * manages only their own. The uploaded photo is kept and a transparent copy is made from it (`strength` can be redone).
 */
class SignatureController extends Controller
{
    public function __construct(private readonly SignatureImage $images) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $admin = $user->hasRole(Role::Admin);
        $own = Signature::where('scope', 'user')->when(! $admin, fn ($q) => $q->where('user_id', $user->id))->with('user:id,name')->get();

        return response()->json(['data' => [
            'school' => $admin ? Signature::where('scope', 'school')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($s) => $this->present($s))->values() : [],
            'personal' => $own->map(fn ($s) => $this->present($s))->values(),
            // The teachers who are homeroom of a section this year: the admin can upload for them.
            'homeroom_teachers' => $admin ? $this->homeroomTeachers() : [],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:6144'],
            'scope' => ['required', 'in:school,user'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['nullable', 'string', 'max:120'],
            'signer_name' => ['nullable', 'string', 'max:120'],
            'strength' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $admin = $user->hasRole(Role::Admin);
        if ($data['scope'] === 'school') {
            abort_unless($admin, 403);
            if (blank($data['title'] ?? null)) {
                throw ValidationException::withMessages(['title' => [__('signatures.title_required')]]);
            }
            $owner = null;
        } else {
            // A personal signature: a teacher's own; the admin may upload one for a teacher.
            $owner = $admin && ! empty($data['user_id']) ? User::findOrFail($data['user_id']) : $user;
            abort_unless($admin || $owner->id === $user->id, 403);
            abort_unless($owner->hasRole(Role::Teacher, Role::Admin), 422);
        }

        $bytes = (string) file_get_contents($request->file('image')->getRealPath());
        $strength = (int) ($data['strength'] ?? 50);
        $png = $this->images->process($bytes, $strength); // refuses a picture with no signature in it, before anything is saved

        $signature = $owner ? Signature::where('scope', 'user')->where('user_id', $owner->id)->first() : null;
        $this->forget($signature);
        $signature ??= new Signature(['scope' => $data['scope'], 'user_id' => $owner?->id, 'created_by' => $user->id]);
        $name = Str::uuid()->toString();
        $original = 'signatures/'.$name.'-original.'.$request->file('image')->getClientOriginalExtension();
        $image = 'signatures/'.$name.'.png';
        Storage::put($original, $bytes);
        Storage::put($image, $png);
        $signature->fill([
            'title' => $data['scope'] === 'school' ? trim($data['title']) : null, 'signer_name' => $data['signer_name'] ?? $owner?->name,
            'original_path' => $original, 'image_path' => $image, 'strength' => $strength, 'is_active' => true,
            'sort_order' => $signature->exists ? $signature->sort_order : (int) Signature::where('scope', $data['scope'])->max('sort_order') + 1,
        ])->save();

        return response()->json(['data' => $this->present($signature->load('user:id,name'))], 201);
    }

    /** Makes the transparent copy again from the kept photo, with another strength (the photo is not uploaded again). */
    public function reprocess(Request $request, Signature $signature): JsonResponse
    {
        $this->authorizeFor($request, $signature);
        $data = $request->validate(['strength' => ['required', 'integer', 'between:0,100']]);

        $png = $this->images->process(Storage::get($signature->original_path), $data['strength']);
        Storage::put($signature->image_path, $png);
        $signature->update(['strength' => $data['strength']]);

        return response()->json(['data' => $this->present($signature->load('user:id,name'))]);
    }

    public function update(Request $request, Signature $signature): JsonResponse
    {
        $this->authorizeFor($request, $signature);
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'], 'signer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
        ]);
        if ($signature->scope === 'school' && array_key_exists('title', $data) && blank($data['title'])) {
            throw ValidationException::withMessages(['title' => [__('signatures.title_required')]]);
        }
        $signature->update($data);

        return response()->json(['data' => $this->present($signature->load('user:id,name'))]);
    }

    public function destroy(Request $request, Signature $signature): Response
    {
        $this->authorizeFor($request, $signature);
        $this->forget($signature);
        $signature->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** The admin manages every signature; a teacher only their own personal one. */
    private function authorizeFor(Request $request, Signature $signature): void
    {
        $user = $this->user($request);
        abort_unless($user->hasRole(Role::Admin) || ($signature->scope === 'user' && $signature->user_id === $user->id), 403);
    }

    /** Removes the files of a signature that is being replaced or deleted. */
    private function forget(?Signature $signature): void
    {
        if ($signature?->exists) {
            Storage::delete([$signature->original_path, $signature->image_path]);
        }
    }

    /** @return list<array{id: int, name: string, classrooms: list<string>}> */
    private function homeroomTeachers(): array
    {
        $year = AcademicYear::where('is_current', true)->first();

        return Classroom::where('academic_year_id', $year?->id)->whereNotNull('homeroom_teacher_id')->with('homeroomTeacher:id,name')->orderBy('name')->get()
            ->groupBy('homeroom_teacher_id')->map(fn ($rooms) => ['id' => $rooms->first()->homeroomTeacher->id, 'name' => $rooms->first()->homeroomTeacher->name, 'classrooms' => $rooms->pluck('name')->all()])
            ->sortBy('name')->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(Signature $s): array
    {
        return [
            'id' => $s->id, 'scope' => $s->scope, 'title' => $s->title, 'signer_name' => $s->signer_name, 'strength' => $s->strength, 'is_active' => $s->is_active, 'sort_order' => $s->sort_order,
            'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name] : null, 'image' => $s->dataUri(),
        ];
    }
}
