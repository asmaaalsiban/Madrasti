<?php

declare(strict_types=1);

namespace App\Shared\Attachments;

use App\Models\User;
use App\Modules\Finance\Models\OnlinePayment;
use App\Modules\Finance\Services\FinanceAccess;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;
use App\Shared\Enums\Role;
use Illuminate\Database\Eloquent\Model;

/** Who may add/remove the files of an owner, and who may open them. */
class AttachmentAccess
{
    /** The task's giver (or management) manages the task's files; a person manages their own hand-in; a survey's author (or management) its files. */
    public function manages(User $user, Model $owner): bool
    {
        return match (true) {
            $owner instanceof Task => $owner->created_by === $user->id || $user->hasRole(Role::Admin),
            $owner instanceof TaskAssignee => $owner->user_id === $user->id,
            $owner instanceof Survey => $owner->created_by === $user->id || $user->hasRole(Role::Admin),
            // The proof of a transfer: its payer adds it while it waits for the school.
            $owner instanceof OnlinePayment => $owner->user_id === $user->id && $owner->status === OnlinePayment::PENDING,
            default => false,
        };
    }

    /** The people of the task see its files; a hand-in is seen by its author, the giver and management; a survey by its author and its recipients. */
    public function views(User $user, Model $owner): bool
    {
        if ($this->manages($user, $owner)) {
            return true;
        }

        return match (true) {
            $owner instanceof Task => $owner->assignees()->where('user_id', $user->id)->exists(),
            $owner instanceof TaskAssignee => $owner->task->created_by === $user->id || $user->hasRole(Role::Admin),
            $owner instanceof Survey => $owner->recipients()->where('user_id', $user->id)->exists(),
            // The proof is seen by the payer, the principal and the accountant.
            $owner instanceof OnlinePayment => $owner->user_id === $user->id || $user->hasRole(Role::Admin) || FinanceAccess::manages($user),
            default => false,
        };
    }
}
