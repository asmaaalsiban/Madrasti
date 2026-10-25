<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Communication\Models\Conversation;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Services\ChatAccess;
use App\Modules\Communication\Services\Notifier;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat: direct conversations (teacher with a parent or a student, anyone with the admin) and section groups made by a
 * teacher or the admin. Messages are text and/or one photo; the sender sees whether they were read.
 */
class ChatController extends Controller
{
    private const PAGE = 100;

    public function __construct(private readonly ChatAccess $access, private readonly Notifier $notifier) {}

    /** The people the user can start a chat with, with a label that says who they are. */
    public function contacts(Request $request): JsonResponse
    {
        $me = $request->user();
        $people = $this->access->contacts($me, $request->query('search'));

        return response()->json(['data' => $people->map(fn (User $u) => $this->person($u, $me))->values()]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $me = $request->user();
        $rows = Conversation::whereHas('participants', fn ($q) => $q->whereKey($me->id))->with(['participants', 'classroom'])
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(self::PAGE)->get();
        $last = Message::whereIn('id', Message::selectRaw('max(id)')->whereIn('conversation_id', $rows->pluck('id'))->groupBy('conversation_id'))->get()->keyBy('conversation_id');
        $unread = $this->unreadByConversation($me);

        return response()->json(['data' => $rows->map(fn (Conversation $c) => $this->shape($c, $me, $last->get($c->id), $unread[$c->id] ?? 0))->values()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['count' => array_sum($this->unreadByConversation($request->user()))]]);
    }

    /** Opens (or creates) the direct chat with another person. */
    public function direct(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $other = User::findOrFail($data['user_id']);
        abort_unless($this->access->canMessage($me, $other), 403, __('chat.not_allowed'));

        $existing = Conversation::where('type', 'direct')
            ->whereHas('participants', fn ($q) => $q->whereKey($me->id))->whereHas('participants', fn ($q) => $q->whereKey($other->id))->first();
        $conversation = $existing ?? DB::transaction(function () use ($me, $other): Conversation {
            $c = Conversation::create(['type' => 'direct', 'created_by' => $me->id]);
            $c->participants()->attach([$me->id, $other->id]);

            return $c;
        });

        return response()->json(['data' => $this->shape($conversation->load(['participants', 'classroom']), $me, null, 0)], $existing ? 200 : 201);
    }

    /** A section group: its students (and parents if asked) and teachers. Made by a teacher of the section or the admin. */
    public function group(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->hasRole(Role::Admin, Role::Teacher), 403);
        $data = $request->validate([
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'include_parents' => ['sometimes', 'boolean'],
            'members_can_post' => ['sometimes', 'boolean'],
        ]);
        if (! $me->hasRole(Role::Admin) && ! $this->access->sections($me)->contains((int) $data['classroom_id'])) {
            throw ValidationException::withMessages(['classroom_id' => [__('teacher_tools.not_your_class')]]);
        }
        $room = Classroom::findOrFail($data['classroom_id']);

        $group = Conversation::create([
            'type' => 'group', 'title' => $data['title'] ?? $room->name, 'classroom_id' => $room->id, 'created_by' => $me->id,
            'include_parents' => $data['include_parents'] ?? false, 'members_can_post' => $data['members_can_post'] ?? true,
        ]);
        $this->access->syncGroup($group);

        return response()->json(['data' => $this->shape($group->load(['participants', 'classroom']), $me, null, 0)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->mine($me, $id);

        return response()->json(['data' => $this->shape($conversation, $me, $conversation->messages()->latest('id')->first(), $this->unreadByConversation($me)[$id] ?? 0)]);
    }

    /** The messages (the latest ones, or those after `after_id`); opening them marks them read. */
    public function messages(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->mine($me, $id);
        $data = $request->validate(['after_id' => ['sometimes', 'integer', 'min:0']]);

        $query = $conversation->messages()->with('sender');
        $rows = isset($data['after_id'])
            ? $query->where('id', '>', $data['after_id'])->orderBy('id')->limit(self::PAGE)->get()
            : $query->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();

        $this->markRead($me, $conversation, $rows);
        $readers = DB::table('message_reads')->whereIn('message_id', $rows->pluck('id'))->get()->groupBy('message_id');
        $recipients = $conversation->participants->count() - 1;

        return response()->json(['data' => $rows->map(fn (Message $m) => $this->message($m, $me, $readers->get($m->id, collect())->where('user_id', '!=', $m->sender_id)->count(), $recipients))->values()]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->mine($me, $id);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        if (blank($data['body'] ?? null) && ! $request->hasFile('image')) {
            throw ValidationException::withMessages(['body' => [__('chat.empty_message')]]);
        }
        $this->guardPosting($me, $conversation);

        $message = DB::transaction(function () use ($request, $conversation, $me, $data): Message {
            $message = Message::create([
                'conversation_id' => $conversation->id, 'sender_id' => $me->id, 'body' => $data['body'] ?? null,
                'attachment_path' => $request->hasFile('image') ? $request->file('image')->store('chat') : null,
            ]);
            $conversation->update(['last_message_at' => now()]);

            return $message;
        });

        $recipients = $conversation->participants->pluck('id')->reject(fn ($uid) => $uid === $me->id);
        $this->notifier->toUsersOnce($recipients, 'chat_message', $conversation->id, ['sender' => $me->name, 'group' => $conversation->type === 'group' ? $conversation->title : null], '/chat?c='.$conversation->id);

        return response()->json(['data' => $this->message($message->load('sender'), $me, 0, $recipients->count())], 201);
    }

    /** The sender deletes their own message. */
    public function destroy(Request $request, int $id): Response
    {
        $message = Message::findOrFail($id);
        abort_unless($message->sender_id === $request->user()->id, 403);
        $message->delete();

        return response()->noContent();
    }

    public function image(Request $request, int $id): StreamedResponse
    {
        $message = Message::findOrFail($id);
        $this->mine($request->user(), $message->conversation_id);
        abort_unless($message->attachment_path !== null && Storage::exists($message->attachment_path), 404);

        return Storage::response($message->attachment_path);
    }

    private function mine(User $me, int $id): Conversation
    {
        $conversation = Conversation::with(['participants', 'classroom'])->findOrFail($id);
        abort_unless($conversation->participants->contains('id', $me->id), 404);
        if ($conversation->type === 'group') {
            $this->access->syncGroup($conversation);
            $conversation->load('participants');
            abort_unless($conversation->participants->contains('id', $me->id), 404);
        }

        return $conversation;
    }

    /** Direct chats need the pair to still be allowed; a group may be restricted to the staff. */
    private function guardPosting(User $me, Conversation $conversation): void
    {
        if ($conversation->type === 'direct') {
            $other = $conversation->participants->firstWhere('id', '!=', $me->id);
            abort_unless($other !== null && $this->access->canMessage($me, $other), 403, __('chat.not_allowed'));

            return;
        }
        abort_unless($conversation->members_can_post || $me->hasRole(Role::Admin, Role::Teacher), 403, __('chat.group_staff_only'));
    }

    /** @param  Collection<int, Message>  $messages */
    private function markRead(User $me, Conversation $conversation, Collection $messages): void
    {
        $unread = $messages->where('sender_id', '!=', $me->id)->pluck('id');
        if ($unread->isNotEmpty()) {
            $have = DB::table('message_reads')->where('user_id', $me->id)->whereIn('message_id', $unread)->pluck('message_id');
            $rows = $unread->diff($have)->map(fn ($mid) => ['message_id' => $mid, 'user_id' => $me->id, 'read_at' => now()])->values()->all();
            if ($rows !== []) {
                DB::table('message_reads')->insert($rows);
            }
        }
        DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $me->id)->update(['last_read_at' => now()]);
        $this->notifier->readChat($me->id, $conversation->id);
    }

    /** @return array<int, int> conversation id => messages from others that I have not read */
    private function unreadByConversation(User $me): array
    {
        return DB::table('messages')->join('conversation_participants as cp', fn ($j) => $j->on('cp.conversation_id', '=', 'messages.conversation_id')->where('cp.user_id', $me->id))
            ->where('messages.sender_id', '!=', $me->id)->whereNull('messages.deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('message_reads')->whereColumn('message_reads.message_id', 'messages.id')->where('message_reads.user_id', $me->id))
            ->groupBy('messages.conversation_id')->selectRaw('messages.conversation_id as cid, count(*) as n')->pluck('n', 'cid')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string, mixed> */
    private function person(User $u, User $viewer): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value, 'label' => $this->label($u, $viewer)];
    }

    /** What to show next to a name: a teacher's subject, a student's section, a parent's children. */
    private function label(User $u, User $viewer): ?string
    {
        return match ($u->role) {
            Role::Teacher => $u->staffProfile?->subject?->name_ar,
            Role::Student => $u->student?->enrollments()->latest('academic_year_id')->first()?->classroom?->name,
            Role::Guardian => $u->children()->get()->map(fn ($s) => $s->name_ar)->implode('، ') ?: null,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function shape(Conversation $c, User $me, ?Message $last, int $unread): array
    {
        $other = $c->type === 'direct' ? $c->participants->firstWhere('id', '!=', $me->id) : null;
        $staffOnly = $c->type === 'group' && ! $c->members_can_post && ! $me->hasRole(Role::Admin, Role::Teacher);

        return [
            'id' => $c->id, 'type' => $c->type, 'title' => $c->type === 'group' ? $c->title : $other?->name,
            'other' => $other ? $this->person($other->loadMissing('staffProfile.subject'), $me) : null,
            'classroom' => $c->classroom?->only(['id', 'name']), 'include_parents' => $c->include_parents, 'members_can_post' => $c->members_can_post,
            'can_post' => ! $staffOnly, 'members' => $c->participants->count(), 'unread' => $unread,
            'last_message' => $last ? ['body' => $last->body, 'has_image' => $last->attachment_path !== null, 'mine' => $last->sender_id === $me->id, 'at' => $last->created_at->toIso8601String()] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function message(Message $m, User $me, int $readBy, int $recipients): array
    {
        return [
            'id' => $m->id, 'body' => $m->body, 'has_image' => $m->attachment_path !== null, 'created_at' => $m->created_at->toIso8601String(),
            'sender' => ['id' => $m->sender->id, 'name' => $m->sender->name], 'mine' => $m->sender_id === $me->id,
            'read_by' => $readBy, 'recipients' => $recipients, 'read' => $recipients > 0 && $readBy >= $recipients,
        ];
    }
}
