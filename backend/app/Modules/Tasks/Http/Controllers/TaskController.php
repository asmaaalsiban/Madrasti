<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Tasks for staff. Management gives a task to any teacher; a head of department gives one to the teachers of their department
 * (the same subject). Every assignee moves their own part from todo to doing to done and may leave a note; the giver follows the progress.
 */
class TaskController extends Controller
{
    public function __construct(private readonly Notifier $notifier) {}

    /** Tasks given to me. */
    public function mine(Request $request): JsonResponse
    {
        $me = $this->user($request);
        $tasks = Task::with(['creator:id,name', 'assignees.user:id,name'])->whereHas('assignees', fn ($q) => $q->where('user_id', $me->id))->orderByRaw('due_on is null, due_on')->orderByDesc('id')->get();

        return response()->json(['data' => $tasks->map(fn ($t) => $this->present($t, $me))->values(), 'open' => $tasks->filter(fn ($t) => $t->assignees->firstWhere('user_id', $me->id)?->status !== 'done')->count()]);
    }

    /** Tasks I gave (management sees every task). */
    public function given(Request $request): JsonResponse
    {
        $me = $this->user($request);
        abort_unless($this->canGive($me), 403, __('tasks.not_allowed'));
        $tasks = Task::with(['creator:id,name', 'assignees.user:id,name'])->when(! $me->hasRole(Role::Admin), fn ($q) => $q->where('created_by', $me->id))->orderByRaw('due_on is null, due_on')->orderByDesc('id')->get();

        return response()->json(['data' => $tasks->map(fn ($t) => $this->present($t, $me))->values()]);
    }

    /** The people I may give a task to. */
    public function people(Request $request): JsonResponse
    {
        $me = $this->user($request);
        abort_unless($this->canGive($me), 403, __('tasks.not_allowed'));

        return response()->json(['data' => $this->assignable($me)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'staff_type' => $u->staffProfile?->staff_type?->value])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->user($request);
        abort_unless($this->canGive($me), 403, __('tasks.not_allowed'));
        $data = $this->validated($request);
        $ids = $this->checkPeople($me, $data['user_ids']);
        $task = Task::create(collect($data)->except('user_ids')->all() + ['created_by' => $me->id]);
        foreach ($ids as $id) {
            TaskAssignee::create(['task_id' => $task->id, 'user_id' => $id]);
        }
        $this->tell($ids, $me, $task);

        return response()->json(['data' => $this->present($task->load(['creator:id,name', 'assignees.user:id,name']), $me)], 201);
    }

    /** The giver (or management) changes the task and who has it: people who already started keep their progress. */
    public function update(Request $request, Task $task): JsonResponse
    {
        $me = $this->user($request);
        abort_unless($task->created_by === $me->id || $me->hasRole(Role::Admin), 403, __('tasks.not_allowed'));
        $data = $this->validated($request);
        $task->update(collect($data)->except('user_ids')->all());
        $current = $task->assignees()->pluck('user_id');
        $wanted = $this->checkPeople($task->creator, $data['user_ids'], $current->all());
        $task->assignees()->whereNotIn('user_id', $wanted)->delete();
        $added = collect($wanted)->diff($current)->values();
        foreach ($added as $id) {
            TaskAssignee::create(['task_id' => $task->id, 'user_id' => $id]);
        }
        $this->tell($added->all(), $me, $task);

        return response()->json(['data' => $this->present($task->refresh()->load(['creator:id,name', 'assignees.user:id,name']), $me)]);
    }

    public function destroy(Request $request, Task $task): Response
    {
        $me = $this->user($request);
        abort_unless($task->created_by === $me->id || $me->hasRole(Role::Admin), 403, __('tasks.not_allowed'));
        $task->delete();

        return response()->noContent();
    }

    /** An assignee moves their own part of the task. The giver is told when it is done. */
    public function status(Request $request, Task $task): JsonResponse
    {
        $me = $this->user($request);
        $part = TaskAssignee::where('task_id', $task->id)->where('user_id', $me->id)->first();
        abort_if($part === null, 403, __('tasks.not_allowed'));
        $data = $request->validate(['status' => ['required', 'in:'.implode(',', TaskAssignee::STATUSES)], 'note' => ['nullable', 'string', 'max:500']]);
        $finishedNow = $data['status'] === 'done' && $part->status !== 'done';
        $part->update(['status' => $data['status'], 'note' => $data['note'] ?? null, 'done_at' => $data['status'] === 'done' ? ($part->done_at ?? now()) : null]);
        if ($finishedNow && $task->created_by !== $me->id) {
            $this->notifier->toUsers([$task->created_by], 'task_done', ['name' => $me->name, 'title' => $task->title], $this->link($task->creator));
        }

        return response()->json(['data' => $this->present($task->load(['creator:id,name', 'assignees.user:id,name']), $me)]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** Management, and a head of department who has a department. */
    private function canGive(User $user): bool
    {
        return $user->hasRole(Role::Admin) || $this->departmentOf($user) !== null;
    }

    private function departmentOf(User $user): ?int
    {
        $profile = $user->staffProfile()->first();

        return $profile?->staff_type === StaffType::HeadOfDepartment ? $profile->subject_id : null;
    }

    /** @return Collection<int, User> */
    private function assignable(User $giver): Collection
    {
        $q = User::query()->where('is_active', true)->whereKeyNot($giver->id)->with('staffProfile')->orderBy('name');
        if ($giver->hasRole(Role::Admin)) {
            return $q->where('role', Role::Teacher)->get();
        }
        $subject = $this->departmentOf($giver);

        return $subject === null ? collect() : $q->whereHas('staffProfile', fn ($p) => $p->where('subject_id', $subject)->whereIn('staff_type', StaffType::valuesIn('teaching')))->get();
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $keep  people who already have the task (never refused, so an old task can still be edited)
     * @return list<int>
     */
    private function checkPeople(User $giver, array $ids, array $keep = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $allowed = $this->assignable($giver)->pluck('id')->merge($keep)->all();
        if (array_diff($ids, $allowed) !== []) {
            throw ValidationException::withMessages(['user_ids' => [__('tasks.person_not_allowed')]]);
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:3000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'], 'priority' => ['required', 'in:'.implode(',', Task::PRIORITIES)],
            'user_ids' => ['required', 'array', 'min:1'], 'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
    }

    /** @param  iterable<int>  $ids */
    private function tell(iterable $ids, User $giver, Task $task): void
    {
        $this->notifier->toUsers($ids, 'task_new', ['name' => $giver->name, 'title' => $task->title], '/teacher/tasks');
    }

    private function link(?User $user): string
    {
        return $user?->hasRole(Role::Admin) ? '/admin/tasks' : '/teacher/tasks';
    }

    /** @return array<string, mixed> */
    private function present(Task $t, User $me): array
    {
        $today = now()->toDateString();
        $mine = $t->assignees->firstWhere('user_id', $me->id);
        $open = $t->assignees->where('status', '!=', 'done')->count();

        return [
            'id' => $t->id, 'title' => $t->title, 'description' => $t->description, 'due_on' => $t->due_on?->toDateString(), 'priority' => $t->priority,
            'created_by' => ['id' => $t->creator?->id, 'name' => $t->creator?->name], 'created_at' => $t->created_at?->toIso8601String(),
            'can_edit' => $t->created_by === $me->id || $me->hasRole(Role::Admin),
            'overdue' => $t->due_on !== null && $t->due_on->toDateString() < $today && $open > 0,
            'my_status' => $mine?->status, 'my_note' => $mine?->note,
            'done_count' => $t->assignees->count() - $open, 'total' => $t->assignees->count(),
            'assignees' => $t->assignees->map(fn ($a) => ['user_id' => $a->user_id, 'name' => $a->user?->name, 'status' => $a->status, 'note' => $a->note, 'done_at' => $a->done_at?->toIso8601String()])->values(),
        ];
    }
}
