<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\DataRequest;
use App\Modules\Auth\Services\PersonalDataExport;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Communication\Services\PhotoConsent;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "My data": everybody can download a copy of their own data (a parent also of each child's) and ask the school to delete or correct it.
 * The school (principal) answers requests; records the law makes it keep (payments) are not deleted.
 */
class PrivacyController extends Controller
{
    public function __construct(private readonly PersonalDataExport $export, private readonly Notifier $notifier, private readonly PhotoConsent $consent) {}

    /** A copy of my data, or of one of my children's (`?student_id=`), as a JSON file. */
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $me = $this->user($request);
        $studentId = $request->query('student_id');
        $data = $studentId === null ? $this->export->forUser($me) : $this->export->forStudent($this->mine($me, (int) $studentId));

        return response()->json($data, 200, ['Content-Disposition' => 'attachment; filename="my-data-'.now()->format('Y-m-d').'.json"'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function mineList(Request $request): JsonResponse
    {
        $me = $this->user($request);
        $rows = DataRequest::where('user_id', $me->id)->latest('id')->limit(20)->get();
        $children = $me->hasRole(Role::Guardian) ? $me->children()->get(['students.id', 'name_ar', 'name_en', 'photo_consent']) : collect();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r))->values(), 'children' => $children->map->only(['id', 'name_ar', 'name_en', 'photo_consent'])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->user($request);
        $data = $request->validate(['type' => ['required', 'in:deletion,correction'], 'student_id' => ['nullable', 'integer'], 'message' => ['nullable', 'string', 'max:1000']]);
        $studentId = $data['student_id'] ?? null;
        if ($studentId !== null) {
            $this->mine($me, (int) $studentId);
        }
        if (DataRequest::where('user_id', $me->id)->where('student_id', $studentId)->where('type', $data['type'])->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['type' => [__('privacy.already_asked')]]);
        }
        $req = DataRequest::create(['user_id' => $me->id, 'student_id' => $studentId, 'type' => $data['type'], 'status' => 'pending', 'message' => $data['message'] ?? null]);
        $this->notifier->toUsers(User::where('role', Role::Admin)->where('is_active', true)->pluck('id'), 'data_request', ['name' => $me->name, 'type' => $data['type']], '/admin/privacy-requests');

        return response()->json(['data' => $this->present($req)], 201);
    }

    /** A parent says yes or no to photos of their child in the class feed; the principal can record it for a family (a paper form). */
    public function setPhotoConsent(Request $request): JsonResponse
    {
        $me = $this->user($request);
        $data = $request->validate(['student_id' => ['required', 'integer'], 'allowed' => ['required', 'boolean']]);
        $student = Student::findOrFail($data['student_id']);
        abort_unless($me->hasRole(Role::Admin) || ($me->hasRole(Role::Guardian) && $student->guardians()->whereKey($me->id)->exists()), 403);
        $this->consent->set($student, (bool) $data['allowed'], $me->id);

        return response()->json(['data' => ['student_id' => $student->id, 'photo_consent' => (bool) $data['allowed']]]);
    }

    /** The principal's view: the mode, and the answer of every student of a section (or all). */
    public function photoConsent(Request $request): JsonResponse
    {
        $this->admin($request);
        $room = $request->query('classroom_id');
        $students = $room ? $this->consent->audience('classroom', (int) $room, null) : $this->consent->audience('school', null, null);

        return response()->json(['mode' => $this->consent->mode(), 'data' => $students->map->only(['id', 'name_ar', 'name_en', 'photo_consent'])->values(), 'missing' => $students->filter(fn ($s) => $s->photo_consent !== true)->count()]);
    }

    public function setPhotoConsentMode(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['mode' => ['required', 'in:'.implode(',', PhotoConsent::MODES)]]);
        $this->consent->setMode($data['mode']);

        return response()->json(['mode' => $data['mode']]);
    }

    /** The principal sees every request. */
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $rows = DataRequest::with('user:id,name,email,role')->orderByRaw("status = 'pending' desc")->latest('id')->limit(200)->get();
        $students = Student::withTrashed()->whereIn('id', $rows->pluck('student_id')->filter())->get(['id', 'name_ar', 'name_en'])->keyBy('id');

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r) + ['user' => $r->user?->only(['id', 'name', 'email']) + ['role' => $r->user?->role->value], 'student' => $students[$r->student_id] ?? null])->values(), 'pending' => $rows->where('status', 'pending')->count()]);
    }

    /** The school's answer: done (say what was done) or refused (say why, e.g. a record the law makes the school keep). */
    public function decide(Request $request, DataRequest $dataRequest): JsonResponse
    {
        $by = $this->admin($request);
        $data = $request->validate(['status' => ['required', 'in:done,refused'], 'answer' => ['required', 'string', 'max:1000']]);
        if ($dataRequest->status !== 'pending') {
            throw ValidationException::withMessages(['status' => [__('privacy.already_decided')]]);
        }
        $dataRequest->update(['status' => $data['status'], 'answer' => $data['answer'], 'handled_by' => $by->id, 'handled_at' => now()]);
        $this->notifier->toUsers([$dataRequest->user_id], 'data_request_answered', ['status' => $data['status']], '/'.$dataRequest->user->role->value.'/privacy');

        return response()->json(['data' => $this->present($dataRequest->refresh())]);
    }

    /** My own child (a parent) or myself (a student), or any student for the principal. */
    private function mine(User $me, int $studentId): Student
    {
        $student = Student::findOrFail($studentId);
        abort_unless($me->hasRole(Role::Admin) || ($me->hasRole(Role::Guardian) && $student->guardians()->whereKey($me->id)->exists()) || ($me->hasRole(Role::Student) && $student->user_id === $me->id), 403);

        return $student;
    }

    /** @return array<string, mixed> */
    private function present(DataRequest $r): array
    {
        return ['id' => $r->id, 'type' => $r->type, 'status' => $r->status, 'student_id' => $r->student_id, 'message' => $r->message, 'answer' => $r->answer, 'created_at' => $r->created_at?->toIso8601String(), 'handled_at' => $r->handled_at?->toIso8601String()];
    }

    private function admin(Request $request): User
    {
        $user = $this->user($request);
        abort_unless($user->hasRole(Role::Admin), 403);

        return $user;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
