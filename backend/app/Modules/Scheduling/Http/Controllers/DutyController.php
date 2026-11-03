<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Scheduling\Models\DutyAssignment;
use App\Modules\Scheduling\Models\DutyPost;
use App\Modules\Scheduling\Services\DutyScheduler;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Staff duties: the principal sets the posts, generates and edits the roster; each person reads their own. */
class DutyController extends Controller
{
    public function __construct(private readonly DutyScheduler $scheduler) {}

    // ---- posts -----------------------------------------------------------------------------------------------------

    public function posts(): JsonResponse
    {
        return response()->json(['data' => DutyPost::orderBy('starts_at')->get()->map(fn ($p) => $this->presentPost($p))->values()]);
    }

    public function storePost(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentPost(DutyPost::create($this->postData($request)))], 201);
    }

    public function updatePost(Request $request, DutyPost $post): JsonResponse
    {
        $post->update($this->postData($request));

        return response()->json(['data' => $this->presentPost($post->refresh())]);
    }

    public function destroyPost(DutyPost $post): Response
    {
        $post->delete(); // its past duties keep the place name

        return response()->noContent();
    }

    // ---- the roster ------------------------------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'user_id' => ['nullable', 'integer']]);
        $from = $f['from'] ?? now()->startOfMonth()->toDateString();
        $to = $f['to'] ?? now()->endOfMonth()->toDateString();
        $rows = DutyAssignment::with('user:id,name')->whereBetween('date', [$from, $to])->when($f['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))->orderBy('date')->orderBy('starts_at')->get();

        return response()->json(['from' => $from, 'to' => $to, 'data' => $rows->map(fn ($d) => $this->present($d))->values()]);
    }

    /** Builds the roster of a range (or only previews it with `dry_run`). Locked duties are kept. */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'exclude_dates' => ['nullable', 'array'], 'exclude_dates.*' => ['date_format:Y-m-d'],
            'post_ids' => ['nullable', 'array'], 'post_ids.*' => ['integer'], 'max_per_day' => ['nullable', 'integer', 'between:1,3'], 'dry_run' => ['sometimes', 'boolean'],
        ]);
        $plan = $this->scheduler->plan($data);
        $dry = $request->boolean('dry_run');
        if (! $dry) {
            $this->scheduler->apply($plan, $data['from'], $data['to']);
        }

        return response()->json(['data' => ['dry_run' => $dry, 'duties' => count($plan['rows']), 'days' => $plan['days'], 'posts' => $plan['posts'], 'warnings' => $plan['warnings']]]);
    }

    /** One duty by hand (it is locked, so regenerating keeps it). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'duty_post_id' => ['nullable', 'integer', 'exists:duty_posts,id'], 'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required_without:duty_post_id', 'nullable', 'date_format:H:i'], 'ends_at' => ['required_without:duty_post_id', 'nullable', 'date_format:H:i'], 'location' => ['required_without:duty_post_id', 'nullable', 'string', 'max:120']]);
        $post = isset($data['duty_post_id']) ? DutyPost::find($data['duty_post_id']) : null;
        $start = isset($data['starts_at']) ? $data['starts_at'].':00' : substr((string) $post->starts_at, 0, 8);
        $end = isset($data['ends_at']) ? $data['ends_at'].':00' : substr((string) $post->ends_at, 0, 8);
        $this->check(null, (int) $data['user_id'], $data['date'], $start, $end);
        $duty = DutyAssignment::create(['user_id' => $data['user_id'], 'duty_post_id' => $post?->id, 'date' => $data['date'], 'starts_at' => $start, 'ends_at' => $end, 'location' => $data['location'] ?? $post->name, 'is_locked' => true]);

        return response()->json(['data' => $this->present($duty->load('user:id,name'))], 201);
    }

    public function update(Request $request, DutyAssignment $duty): JsonResponse
    {
        $data = $request->validate(['user_id' => ['sometimes', 'integer', 'exists:users,id'], 'date' => ['sometimes', 'date_format:Y-m-d'], 'starts_at' => ['sometimes', 'date_format:H:i'], 'ends_at' => ['sometimes', 'date_format:H:i'], 'is_locked' => ['sometimes', 'boolean']]);
        $user = $data['user_id'] ?? $duty->user_id;
        $date = $data['date'] ?? $duty->date->toDateString();
        $start = isset($data['starts_at']) ? $data['starts_at'].':00' : (string) $duty->starts_at;
        $end = isset($data['ends_at']) ? $data['ends_at'].':00' : (string) $duty->ends_at;
        $this->check($duty, (int) $user, $date, $start, $end);
        // A hand-made change is locked, unless the lock is set explicitly.
        $duty->update(['user_id' => $user, 'date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'is_locked' => $data['is_locked'] ?? true]);

        return response()->json(['data' => $this->present($duty->refresh()->load('user:id,name'))]);
    }

    public function destroy(DutyAssignment $duty): Response
    {
        $duty->delete();

        return response()->noContent();
    }

    /** My duties: today and what is coming, and the last month. */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(Role::Teacher) || $user->hasRole(Role::Admin), 403);
        $rows = DutyAssignment::with('user:id,name')->where('user_id', $user->id)->whereDate('date', '>=', now()->subDays(30))->orderBy('date')->orderBy('starts_at')->get();
        $today = now()->toDateString();

        return response()->json(['data' => ['upcoming' => $rows->filter(fn ($d) => $d->date->toDateString() >= $today)->map(fn ($d) => $this->present($d))->values(), 'past' => $rows->filter(fn ($d) => $d->date->toDateString() < $today)->reverse()->map(fn ($d) => $this->present($d))->values(), 'total' => DutyAssignment::where('user_id', $user->id)->count()]]);
    }

    /** A person cannot have two duties that overlap on a day, and a duty ends after it starts. */
    private function check(?DutyAssignment $except, int $userId, string $date, string $start, string $end): void
    {
        if ($end <= $start) {
            throw ValidationException::withMessages(['ends_at' => [__('duties.time_order')]]);
        }
        $clash = DutyAssignment::where('user_id', $userId)->whereDate('date', $date)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->when($except, fn ($q) => $q->whereKeyNot($except->id))->exists();
        if ($clash) {
            throw ValidationException::withMessages(['user_id' => [__('duties.clash')]]);
        }
    }

    /** @return array<string, mixed> */
    private function postData(Request $request): array
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'], 'staff_count' => ['required', 'integer', 'between:1,10'],
            'days' => ['nullable', 'array'], 'days.*' => ['integer', 'between:0,6'], 'is_active' => ['sometimes', 'boolean']]);
        $d['days'] = empty($d['days']) ? null : array_values(array_unique($d['days']));

        return $d;
    }

    /** @return array<string, mixed> */
    private function presentPost(DutyPost $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'starts_at' => substr((string) $p->starts_at, 0, 5), 'ends_at' => substr((string) $p->ends_at, 0, 5), 'staff_count' => $p->staff_count, 'days' => $p->days, 'is_active' => $p->is_active];
    }

    /** @return array<string, mixed> */
    private function present(DutyAssignment $d): array
    {
        return ['id' => $d->id, 'date' => $d->date->toDateString(), 'starts_at' => substr((string) $d->starts_at, 0, 5), 'ends_at' => substr((string) $d->ends_at, 0, 5), 'location' => $d->location, 'post_id' => $d->duty_post_id, 'is_locked' => $d->is_locked, 'user' => ['id' => $d->user->id, 'name' => $d->user->name]];
    }
}
