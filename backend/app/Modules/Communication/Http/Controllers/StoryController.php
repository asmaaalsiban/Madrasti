<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Communication\Models\Story;
use App\Modules\Communication\Models\StoryComment;
use App\Modules\Communication\Models\StoryMedia;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Communication\Services\StoryAccess;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The class feed: teachers and the principal post news and photos; students and parents read, react and comment. */
class StoryController extends Controller
{
    public function __construct(private readonly StoryAccess $access, private readonly Notifier $notifier) {}

    /** The feed of the signed-in person: pinned first, then newest. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $page = $this->access->visibleTo($user)->with(['author.staffProfile', 'classroom:id,name', 'gradeLevel:id,name_ar,name_en', 'media:id,story_id,position'])->withCount(['reactions', 'comments'])
            ->orderByDesc('is_pinned')->orderByDesc('created_at')->orderByDesc('id')->paginate(8);
        $liked = $page->getCollection()->isEmpty() ? collect() : DB::table('story_reactions')->where('user_id', $user->id)->whereIn('story_id', $page->getCollection()->pluck('id'))->pluck('story_id')->flip();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Story $s) => $this->present($s, $user, $liked->has($s->id)))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'can_post' => $this->access->canPost($user),
        ]);
    }

    /** Where this person can post: their classes (a teacher) or any class, grade or the whole school (the principal). */
    public function targets(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->access->canPost($user), 403);
        $rooms = $this->access->postableClassrooms($user)->load('gradeLevel:id,name_ar,name_en')->sortBy('name');

        return response()->json(['data' => [
            'classrooms' => $rooms->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'grades' => $user->hasRole(Role::Admin) ? $rooms->pluck('gradeLevel')->unique('id')->map(fn ($g) => ['id' => $g->id, 'name_ar' => $g->name_ar, 'name_en' => $g->name_en])->values() : [],
            'school' => $user->hasRole(Role::Admin),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->access->canPost($user), 403);
        $data = $request->validate([
            'audience' => ['required', 'in:classroom,grade,school'], 'classroom_id' => ['required_if:audience,classroom', 'nullable', 'integer', 'exists:classrooms,id'], 'grade_level_id' => ['required_if:audience,grade', 'nullable', 'integer', 'exists:grade_levels,id'],
            'body' => ['nullable', 'string', 'max:2000'], 'comments_enabled' => ['sometimes', 'boolean'], 'images' => ['nullable', 'array', 'max:'.Story::MAX_PHOTOS], 'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        if (blank($data['body'] ?? null) && ! $request->hasFile('images')) {
            throw ValidationException::withMessages(['body' => [__('stories.empty')]]);
        }
        if (! $user->hasRole(Role::Admin)) {
            // A teacher posts to one of their own classes only.
            abort_unless($data['audience'] === 'classroom' && $this->access->postableClassrooms($user)->contains('id', (int) $data['classroom_id']), 403, __('stories.not_allowed'));
        }
        $story = Story::create([
            'author_id' => $user->id, 'audience' => $data['audience'], 'classroom_id' => $data['audience'] === 'classroom' ? $data['classroom_id'] : null, 'grade_level_id' => $data['audience'] === 'grade' ? $data['grade_level_id'] : null,
            'body' => filled($data['body'] ?? null) ? trim($data['body']) : null, 'comments_enabled' => $request->boolean('comments_enabled', true),
        ]);
        if ($request->hasFile('images')) {
            StoryMedia::addTo($story, $request->file('images'));
        }
        $this->tell($story, $user);

        return response()->json(['data' => $this->present($story->load(['author.staffProfile', 'classroom:id,name', 'gradeLevel:id,name_ar,name_en', 'media:id,story_id,position'])->loadCount(['reactions', 'comments']), $user, false)], 201);
    }

    /** The author or the principal: the text, the pin and whether comments are open. */
    public function update(Request $request, Story $story): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->access->canManage($user, $story), 403);
        $story->update($request->validate(['body' => ['sometimes', 'nullable', 'string', 'max:2000'], 'is_pinned' => ['sometimes', 'boolean'], 'comments_enabled' => ['sometimes', 'boolean']]));

        return response()->json(['data' => $this->present($story->refresh()->load(['author.staffProfile', 'classroom:id,name', 'gradeLevel:id,name_ar,name_en', 'media:id,story_id,position'])->loadCount(['reactions', 'comments']), $user, DB::table('story_reactions')->where('story_id', $story->id)->where('user_id', $user->id)->exists())]);
    }

    public function destroy(Request $request, Story $story): Response
    {
        abort_unless($this->access->canManage($this->user($request), $story), 403);
        StoryMedia::forget($story->media()->get());
        $story->delete();

        return response()->noContent();
    }

    /** A heart: press once to like, again to take it back. */
    public function react(Request $request, Story $story): JsonResponse
    {
        $user = $this->viewer($request, $story);
        $mine = $story->reactions()->where('user_id', $user->id);
        $liked = ! $mine->exists();
        $liked ? $story->reactions()->create(['user_id' => $user->id]) : $mine->delete();

        return response()->json(['data' => ['liked' => $liked, 'count' => $story->reactions()->count()]]);
    }

    public function comments(Request $request, Story $story): JsonResponse
    {
        $user = $this->viewer($request, $story);

        return response()->json(['data' => $story->comments()->with('user:id,name,role')->get()->map(fn (StoryComment $c) => $this->presentComment($c, $user, $story))->values()]);
    }

    public function comment(Request $request, Story $story): JsonResponse
    {
        $user = $this->viewer($request, $story);
        if (! $story->comments_enabled) {
            throw ValidationException::withMessages(['body' => [__('stories.comments_closed')]]);
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $comment = $story->comments()->create(['user_id' => $user->id, 'body' => trim($data['body'])])->load('user:id,name,role');
        if ($story->author_id !== $user->id) {
            $this->notifier->toUsers([$story->author_id], 'story_comment', ['name' => $user->name], $this->linkFor($story->author));
        }

        return response()->json(['data' => $this->presentComment($comment, $user, $story)], 201);
    }

    /** The commenter, the author of the story or the principal. */
    public function removeComment(Request $request, StoryComment $comment): Response
    {
        $user = $this->user($request);
        $story = $comment->story ?? Story::findOrFail($comment->story_id);
        abort_unless($comment->user_id === $user->id || $this->access->canManage($user, $story), 403);
        $comment->delete();

        return response()->noContent();
    }

    /** Opens a photo: only people who may read its story. */
    public function media(Request $request, StoryMedia $media): StreamedResponse
    {
        $story = Story::findOrFail($media->story_id);
        abort_unless($this->access->canView($this->user($request), $story) && Storage::exists($media->path), 404);

        return Storage::response($media->path);
    }

    /** Tells the people the story is for (students and their parents through their section; teachers for the whole school). */
    private function tell(Story $story, User $author): void
    {
        $year = AcademicYear::where('is_current', true)->first();
        $rooms = match ($story->audience) {
            'classroom' => [$story->classroom_id],
            'grade' => Classroom::where('academic_year_id', $year?->id)->where('grade_level_id', $story->grade_level_id)->pluck('id')->all(),
            default => Classroom::where('academic_year_id', $year?->id)->pluck('id')->all(),
        };
        foreach ($rooms as $roomId) {
            $this->notifier->toSection((int) $roomId, 'stories', 'story_new', ['teacher' => $author->name]);
        }
        if ($story->audience === 'school') {
            $this->notifier->toUsers(User::where('role', Role::Teacher)->where('is_active', true)->where('id', '!=', $author->id)->pluck('id'), 'story_new', ['teacher' => $author->name], '/teacher/stories');
        }
    }

    private function linkFor(User $user): string
    {
        return '/'.($user->role === Role::Guardian ? 'parent' : $user->role->value).'/stories';
    }

    private function viewer(Request $request, Story $story): User
    {
        $user = $this->user($request);
        abort_unless($this->access->canView($user, $story), 404);

        return $user;
    }

    /** @return array<string, mixed> */
    private function present(Story $s, User $user, bool $liked): array
    {
        return [
            'id' => $s->id, 'audience' => $s->audience, 'created_at' => $s->created_at->toIso8601String(), 'body' => $s->body, 'is_pinned' => $s->is_pinned, 'comments_enabled' => $s->comments_enabled,
            'author' => ['id' => $s->author->id, 'name' => $s->author->name, 'role' => $s->author->role->value, 'staff_type' => $s->author->staffProfile?->staff_type->value],
            'target' => match ($s->audience) { 'classroom' => ['name_ar' => $s->classroom?->name, 'name_en' => $s->classroom?->name], 'grade' => ['name_ar' => $s->gradeLevel?->name_ar, 'name_en' => $s->gradeLevel?->name_en], default => null },
            'media' => $s->media->pluck('id')->values(), 'reactions_count' => (int) $s->reactions_count, 'liked' => $liked, 'comments_count' => (int) $s->comments_count, 'can_manage' => $this->access->canManage($user, $s),
        ];
    }

    /** @return array<string, mixed> */
    private function presentComment(StoryComment $c, User $user, Story $story): array
    {
        return ['id' => $c->id, 'body' => $c->body, 'created_at' => $c->created_at->toIso8601String(), 'user' => ['id' => $c->user->id, 'name' => $c->user->name, 'role' => $c->user->role->value], 'can_delete' => $c->user_id === $user->id || $this->access->canManage($user, $story)];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
