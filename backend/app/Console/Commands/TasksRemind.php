<?php

namespace App\Console\Commands;

use App\Modules\Communication\Services\Notifier;
use App\Modules\Tasks\Models\TaskAssignee;
use Illuminate\Console\Command;

/** Daily, for one school: reminds people of tasks due tomorrow and tells them (and the giver) about tasks that became overdue. */
class TasksRemind extends Command
{
    protected $signature = 'tasks:remind {--date= : Today (default: today)}';

    protected $description = "Remind staff of tasks due tomorrow or overdue (this school's database)";

    public function handle(Notifier $notifier): int
    {
        $today = $this->option('date') ?: now()->toDateString();
        $tomorrow = date('Y-m-d', strtotime("$today +1 day"));
        $yesterday = date('Y-m-d', strtotime("$today -1 day"));
        $count = 0;
        foreach ([[$tomorrow, 'task_reminder'], [$yesterday, 'task_overdue']] as [$day, $kind]) {
            $parts = TaskAssignee::with('task')->where('status', '!=', 'done')->whereHas('task', fn ($q) => $q->where('due_on', $day))->get();
            foreach ($parts as $part) {
                $notifier->toUsers([$part->user_id], $kind, ['title' => $part->task->title, 'date' => $day], '/teacher/tasks');
                $count++;
            }
        }
        $this->info("$count reminder(s) sent");

        return self::SUCCESS;
    }
}
