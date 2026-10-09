<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TaskWorkflowException;
use App\Http\Controllers\Controller;
use App\Models\ModificationRequest;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Services\TaskWorkflow;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskReviewController extends Controller
{
    public function __construct(private TaskWorkflow $workflow)
    {
    }

    public function show(Task $task): Response
    {
        $task->load(['assignee:id,name', 'creator:id,name']);

        $archived = $task->trashed();

        $events = $task->events()
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TaskEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'note' => $event->note,
                'user_name' => $event->user?->name,
                'created_at' => $event->created_at->toIso8601String(),
            ]);

        $requests = $task->modificationRequests()
            ->with(['requester:id,name', 'resolver:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ModificationRequest $request) => [
                'id' => $request->id,
                'reason' => $request->reason,
                'status' => $request->status,
                'admin_response' => $request->admin_response,
                'requested_by' => $request->requester?->name,
                'resolved_by' => $request->resolver?->name,
                'created_at' => $request->created_at->toIso8601String(),
                'resolved_at' => $request->resolved_at?->toIso8601String(),
            ]);

        $latestSubmission = $events->first(fn (array $event) => $event['type'] === TaskEvent::SUBMITTED);

        return Inertia::render('admin/tasks/Show', [
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'priority' => $task->priority,
                'status' => $task->status,
                'due_date' => $task->due_date->toDateString(),
                'days_until_due' => $task->daysUntilDue(),
                'is_overdue' => ! $archived && $task->isOverdue(),
                'assignee' => $task->assignee?->name,
                'assigned_by' => $task->creator?->name,
                'accepted_at' => $task->accepted_at?->toIso8601String(),
                'submitted_at' => $task->submitted_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
            ],
            'is_archived' => $archived,
            'latest_submission' => $latestSubmission ? [
                'note' => $latestSubmission['note'],
                'submitted_at' => $latestSubmission['created_at'],
                'user_name' => $latestSubmission['user_name'],
            ] : null,
            'events' => $events,
            'modification_requests' => $requests,
            'can' => [
                'review' => ! $archived && $task->status === Task::STATUS_SUBMITTED,
                'edit' => ! $archived && $task->status !== Task::STATUS_COMPLETED,
                'resolve_requests' => ! $archived,
                'comment' => ! $archived,
            ],
        ]);
    }

    public function approve(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->perform($task, fn () => $this->workflow->approve($request->user(), $task, $data['note'] ?? null));
    }

    public function requestChanges(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate([
            'feedback' => ['required', 'string', 'max:2000'],
        ]);

        return $this->perform($task, fn () => $this->workflow->requestChanges($request->user(), $task, $data['feedback']));
    }

    public function comment(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->perform($task, fn () => $this->workflow->comment($request->user(), $task, $data['note']));
    }

    private function perform(Task $task, Closure $action): RedirectResponse
    {
        try {
            $action();
        } catch (TaskWorkflowException $exception) {
            return redirect("/admin/tasks/{$task->id}")->withErrors(['task' => $exception->getMessage()]);
        }

        return redirect("/admin/tasks/{$task->id}");
    }
}