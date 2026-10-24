<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Communication\Models\Announcement;
use App\Modules\Communication\Models\AnnouncementRead;
use App\Modules\Communication\Services\AnnouncementAudience;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Announcements: the admin writes to everyone, a role or a section; a teacher to a section they teach; everybody reads theirs. */
class AnnouncementController extends Controller
{
    private const LIMIT = 100;

    public function __construct(private readonly AnnouncementAudience $audience) {}

    /** The announcements the signed-in user may read: pinned first, then newest; each says whether they read it. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = Announcement::with(['author', 'classroom'])->visibleTo($user)
            ->orderByDesc('is_pinned')->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIMIT)->get();
        $read = AnnouncementRead::where('user_id', $user->id)->whereIn('announcement_id', $rows->pluck('id'))->pluck('announcement_id')->flip();

        return response()->json(['data' => $rows->map(fn (Announcement $a) => $this->shape($a, $user) + ['read' => $read->has($a->id)])]);
    }

    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = Announcement::visibleTo($user)->whereNotIn('id', AnnouncementRead::where('user_id', $user->id)->select('announcement_id'))->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $announcement = Announcement::visibleTo($user)->findOrFail($id);
        AnnouncementRead::firstOrCreate(['announcement_id' => $announcement->id, 'user_id' => $user->id], ['read_at' => now()]);

        return response()->json(['data' => ['read' => true]]);
    }

    /** What the user may publish to: an admin everything, a teacher only the sections they teach. */
    public function options(Request $request): JsonResponse
    {
        $user = $this->publisher($request);
        $classrooms = Classroom::whereIn('id', $this->audience->classroomIds($user))->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => ['can_publish_all' => $user->hasRole(Role::Admin), 'classrooms' => $classrooms]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->publisher($request);
        $announcement = Announcement::create($this->validated($request, $user) + ['author_id' => $user->id]);

        return response()->json(['data' => $this->shape($announcement->load(['author', 'classroom']), $user)], 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $user = $this->publisher($request);
        abort_unless($user->hasRole(Role::Admin) || $announcement->author_id === $user->id, 403);
        $announcement->update($this->validated($request, $user));

        return response()->json(['data' => $this->shape($announcement->load(['author', 'classroom']), $user)]);
    }

    public function destroy(Request $request, Announcement $announcement): Response
    {
        $user = $this->publisher($request);
        abort_unless($user->hasRole(Role::Admin) || $announcement->author_id === $user->id, 403);
        DB::transaction(fn () => $announcement->delete());

        return response()->noContent();
    }

    private function publisher(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(Role::Admin, Role::Teacher), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $user): array
    {
        $isAdmin = $user->hasRole(Role::Admin);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['required', Rule::in($isAdmin ? ['all', 'role', 'classroom'] : ['classroom'])],
            'audience_role' => ['required_if:audience,role', 'nullable', Rule::in(Role::values())],
            'classroom_id' => ['required_if:audience,classroom', 'nullable', 'integer', 'exists:classrooms,id'],
            'is_pinned' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($data['audience'] === 'classroom' && ! $isAdmin && ! $this->audience->classroomIds($user)->contains((int) $data['classroom_id'])) {
            throw ValidationException::withMessages(['classroom_id' => [__('teacher_tools.not_your_class')]]);
        }

        return [
            'title' => $data['title'], 'body' => $data['body'], 'audience' => $data['audience'],
            'audience_role' => $data['audience'] === 'role' ? $data['audience_role'] : null,
            'classroom_id' => $data['audience'] === 'classroom' ? $data['classroom_id'] : null,
            'is_pinned' => $isAdmin && ($data['is_pinned'] ?? false), 'expires_at' => $data['expires_at'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function shape(Announcement $a, User $viewer): array
    {
        return [
            'id' => $a->id, 'title' => $a->title, 'body' => $a->body, 'audience' => $a->audience, 'audience_role' => $a->audience_role,
            'classroom' => $a->classroom?->only(['id', 'name']), 'is_pinned' => $a->is_pinned, 'expires_at' => $a->expires_at?->toIso8601String(),
            'created_at' => $a->created_at->toIso8601String(), 'author' => $a->author->only(['id', 'name']),
            'can_edit' => $viewer->hasRole(Role::Admin) || $a->author_id === $viewer->id,
        ];
    }
}
