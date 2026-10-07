<?php

namespace App\Services;

use App\Exceptions\TaskWorkflowException;
use App\Models\ModificationRequest;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Every status change in the system goes through this class.
 * Each method checks who is acting and what state the task is in,
 * then records a history entry. Use the returned model: the one
 * passed in may be stale.
 */
class TaskWorkflow
{
    // ---- Admin actions ----

    public function create(User $admin, array $data): Task
    {
        $this->requireAdmin($admin);

        return DB::transaction(function () use ($admin, $data) {
            $task = new Task($data);
            $task->created_by = $admin->id;
            $task->status = Task::STATUS_PENDING;
            $task->save();
            $task->load('assignee');

            $this->record($task, $admin, TaskEvent::CREATED, 'Assigned to '.$task->assignee->name);

            return $task;
        });
    }

    public function update(User $admin, Task $task, array $data): Task
    {
        $this->requireAdmin($admin);

        return DB::transaction(function () use ($admin, $task, $data) {
            $task = $this->locked($task);

            if ($task->status === Task::STATUS_COMPLETED) {
                throw new TaskWorkflowException('Completed tasks can not be edited.');
            }

            $previousAssignee = $task->assignee;

            $task->fill($data);

            $reassigned = $task->isDirty('assigned_to');
            $edited = $task->isDirty(['title', 'description', 'priority', 'due_date']);

            if ($reassigned) {
                $task->status = Task::STATUS_PENDING;
                $task->accepted_at = null;
                $task->submitted_at = null;
            }

            $task->save();

            if ($reassigned) {
                $task->load('assignee');

                $this->record(
                    $task,
                    $admin,
                    TaskEvent::REASSIGNED,
                    'Reassigned from '.$previousAssignee->name.' to '.$task->assignee->name,
                );
            }

            if ($edited) {
                $this->record($task, $admin, TaskEvent::EDITED);
            }

            return $task;
        });
    }

    public function approve(User $admin, Task $task, ?string $note = null): Task
    {
        $this->requireAdmin($admin);

        return DB::transaction(function () use ($admin, $task, $note) {
            $task = $this->locked($task);
            $this->requireStatus($task, [Task::STATUS_SUBMITTED], 'approved');

            $task->forceFill([
                'status' => Task::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            $this->record($task, $admin, TaskEvent::APPROVED, $this->clean($note));

            return $task;
        });
    }

    public function requestChanges(User $admin, Task $task, string $feedback): Task
    {
        $this->requireAdmin($admin);

        $feedback = $this->clean($feedback);

        if ($feedback === null) {
            throw new TaskWorkflowException('Feedback is required when requesting changes.');
        }

        return DB::transaction(function () use ($admin, $task, $feedback) {
            $task = $this->locked($task);
            $this->requireStatus($task, [Task::STATUS_SUBMITTED], 'sent back for changes');

            $task->forceFill(['status' => Task::STATUS_CHANGES_REQUESTED])->save();

            $this->record($task, $admin, TaskEvent::CHANGES_REQUESTED, $feedback);

            return $task;
        });
    }

    public function resolveModification(
        User $admin,
        ModificationRequest $request,
        bool $approve,
        ?string $response = null,
    ): ModificationRequest {
        $this->requireAdmin($admin);

        $response = $this->clean($response);

        if (! $approve && $response === null) {
            throw new TaskWorkflowException('A response is required when declining a modification request.');
        }

        return DB::transaction(function () use ($admin, $request, $approve, $response) {
            $request = ModificationRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== ModificationRequest::STATUS_OPEN) {
                throw new TaskWorkflowException('This modification request has already been resolved.');
            }

            $request->forceFill([
                'status' => $approve
                    ? ModificationRequest::STATUS_APPROVED
                    : ModificationRequest::STATUS_DECLINED,
                'admin_response' => $response,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ])->save();

            $this->record(
                $request->task,
                $admin,
                $approve ? TaskEvent::MODIFICATION_APPROVED : TaskEvent::MODIFICATION_DECLINED,
                $response,
            );

            return $request;
        });
    }

    // ---- Assignee actions ----

    public function accept(User $user, Task $task): Task
    {
        return DB::transaction(function () use ($user, $task) {
            $task = $this->locked($task);
            $this->requireAssignee($user, $task);
            $this->requireStatus($task, [Task::STATUS_PENDING], 'accepted');

            $task->forceFill([
                'status' => Task::STATUS_ACCEPTED,
                'accepted_at' => now(),
            ])->save();

            $this->record($task, $user, TaskEvent::ACCEPTED);

            return $task;
        });
    }

    public function submit(User $user, Task $task, ?string $note = null): Task
    {
        return DB::transaction(function () use ($user, $task, $note) {
            $task = $this->locked($task);
            $this->requireAssignee($user, $task);
            $this->requireStatus($task, [Task::STATUS_ACCEPTED, Task::STATUS_CHANGES_REQUESTED], 'submitted');

            $task->forceFill([
                'status' => Task::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            $this->record($task, $user, TaskEvent::SUBMITTED, $this->clean($note));

            return $task;
        });
    }

    public function requestModification(User $user, Task $task, string $reason): ModificationRequest
    {
        $reason = $this->clean($reason);

        if ($reason === null) {
            throw new TaskWorkflowException('A reason is required when requesting a modification.');
        }

        return DB::transaction(function () use ($user, $task, $reason) {
            $task = $this->locked($task);
            $this->requireAssignee($user, $task);
            $this->requireStatus(
                $task,
                [Task::STATUS_PENDING, Task::STATUS_ACCEPTED, Task::STATUS_CHANGES_REQUESTED],
                'modified',
            );

            $hasOpenRequest = $task->modificationRequests()
                ->where('status', ModificationRequest::STATUS_OPEN)
                ->exists();

            if ($hasOpenRequest) {
                throw new TaskWorkflowException('This task already has an open modification request.');
            }

            $request = new ModificationRequest([
                'task_id' => $task->id,
                'requested_by' => $user->id,
                'reason' => $reason,
            ]);
            $request->save();

            $this->record($task, $user, TaskEvent::MODIFICATION_REQUESTED, $reason);

            return $request;
        });
    }

    // ---- Both ----

    public function comment(User $actor, Task $task, string $note): TaskEvent
    {
        $note = $this->clean($note);

        if ($note === null) {
            throw new TaskWorkflowException('A comment can not be empty.');
        }

        if (! $actor->isAdmin() && $task->assigned_to !== $actor->id) {
            throw new AuthorizationException('Only the assignee or an administrator can comment on this task.');
        }

        return $this->record($task, $actor, TaskEvent::COMMENTED, $note);
    }

    // ---- Helpers ----

    private function locked(Task $task): Task
    {
        return Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
    }

    private function requireAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException('Only administrators can do that.');
        }
    }

    private function requireAssignee(User $user, Task $task): void
    {
        if ($task->assigned_to !== $user->id) {
            throw new AuthorizationException('Only the assigned user can do that.');
        }
    }

    private function requireStatus(Task $task, array $allowed, string $action): void
    {
        if (! in_array($task->status, $allowed, true)) {
            throw new TaskWorkflowException(
                "A task that is {$task->status} can not be {$action}."
            );
        }
    }

    private function record(Task $task, User $user, string $type, ?string $note = null): TaskEvent
    {
        return TaskEvent::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'type' => $type,
            'note' => $note,
        ]);
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}