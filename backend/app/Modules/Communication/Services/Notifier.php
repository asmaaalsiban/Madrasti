<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The bell: one row in `notifications` per person. A row holds a kind (the app writes the sentence in the reader's
 * language from it), parameters and the page to open. A problem here never breaks the action that caused it.
 */
class Notifier
{
    public function __construct(private readonly PushNotifier $push) {}

    /**
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $params
     */
    public function toUsers(iterable $userIds, string $kind, array $params, string $link): void
    {
        $rows = [];
        $events = [];
        foreach (collect($userIds)->unique() as $id) {
            $rows[] = $this->row((int) $id, $kind, $params, $link);
            $events[] = ['user' => (int) $id, 'kind' => $kind, 'params' => $params, 'link' => $link];
        }
        $this->insert($rows);
        $this->push->send($events);
    }

    /**
     * A chat message: rings the bell (and the phone) of each recipient only if they have no unread notification for that
     * conversation already, so a busy chat is one notification, not fifty.
     *
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $params
     */
    public function toUsersOnce(iterable $userIds, string $kind, int $conversationId, array $params, string $link): void
    {
        $fresh = collect($userIds)->unique()->reject(fn ($id) => DB::table('notifications')->where('notifiable_id', $id)->whereNull('read_at')
            ->where('data', 'like', '%"kind":"'.$kind.'"%')->where(fn ($q) => $q->where('data', 'like', '%"conversation":'.$conversationId.',%')->orWhere('data', 'like', '%"conversation":'.$conversationId.'}%'))->exists());
        $this->toUsers($fresh, $kind, ['conversation' => $conversationId] + $params, $link);
    }

    /** Marks the chat notifications of a conversation as read (the person opened it). */
    public function readChat(int $userId, int $conversationId): void
    {
        DB::table('notifications')->where('notifiable_id', $userId)->whereNull('read_at')->where('data', 'like', '%"kind":"chat_message"%')
            ->where(fn ($q) => $q->where('data', 'like', '%"conversation":'.$conversationId.',%')->orWhere('data', 'like', '%"conversation":'.$conversationId.'}%'))
            ->update(['read_at' => now()]);
    }

    /**
     * Everyone who follows a section: its students (page /student/{path}) and each student's parents (page /parent/children/{id}/{path},
     * with the child's name in the parameters so a parent with several children knows which one it is about).
     *
     * @param  array<string, mixed>  $params
     */
    public function toSection(int $classroomId, string $path, string $kind, array $params): void
    {
        $year = AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            return;
        }
        $students = Student::with('guardians:users.id')->whereIn('id', Enrollment::where('classroom_id', $classroomId)->where('academic_year_id', $year->id)->select('student_id'))->get();

        $rows = [];
        $events = [];
        $add = function (int $userId, array $withParams, string $link) use (&$rows, &$events, $kind): void {
            $rows[] = $this->row($userId, $kind, $withParams, $link);
            $events[] = ['user' => $userId, 'kind' => $kind, 'params' => $withParams, 'link' => $link];
        };
        foreach ($students as $student) {
            if ($student->user_id !== null) {
                $add($student->user_id, $params, "/student/{$path}");
            }
            foreach ($student->guardians as $guardian) {
                // The class feed is one page for the whole family; the other sections belong to one child.
                $add($guardian->id, $params + ['child_ar' => $student->name_ar, 'child_en' => $student->name_en ?: $student->name_ar], $path === 'stories' ? '/parent/stories' : "/parent/children/{$student->id}/{$path}");
            }
        }
        $this->insert($rows);
        $this->push->send($events);
    }

    /** @param  array<string, mixed>  $params */
    private function row(int $userId, string $kind, array $params, string $link): array
    {
        return [
            'id' => (string) Str::uuid(), 'type' => 'app', 'notifiable_type' => (new User)->getMorphClass(), 'notifiable_id' => $userId,
            'data' => json_encode(['kind' => $kind, 'params' => $params, 'link' => $link], JSON_UNESCAPED_UNICODE), 'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function insert(array $rows): void
    {
        try {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('notifications')->insert($chunk);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
