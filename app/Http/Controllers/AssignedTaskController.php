<?php

namespace App\Http\Controllers;

use App\Exceptions\TaskWorkflowException;
use App\Models\ModificationRequest;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Services\TaskWorkflow;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssignedTaskController extends Controller
{
    public function __construct(private TaskWorkflow $workflow)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Admins are never assigned tasks, so their list is the admin one.
        if ($user->isAdmin()) {
            return redirect('/admin/tasks');
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in([...Task::STATUSES, 'overdue'])],
        ]);

        $status = $validated['status'] ?? null;

        $mine = fn () => Task::query()->where('assigned_to', $user->id);

        $tasks = $mine()
            ->when($status === null, fn ($query) => $query->where('status', '!=', Task::STATUS_COMPLETED))
            ->when($status === 'overdue', fn ($query) => $query->overdue())
            ->when(
                $status && $status !== 'overdue',
                fn ($query) => $query->where('status', $status),
            )
            ->when(
                $status === 'completed',
                fn ($query) => $query->orderByDesc('completed_at'),
                fn ($query) => $query->orderBy('due_date'),
            )
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Task $task) => $this->row($task));

        $byStatus = $mine()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (Task::STATUSES as $name) {
            $counts[$name] = (int) ($byStatus[$name] ?? 0);
        }

        $counts['active'] = (int) $byStatus->sum() - $counts[Task::STATUS_COMPLETED];
        $counts['overdue'] = $mine()->overdue()->count();

        return Inertia::render('tasks/Index', [
            'tasks' => $tasks,
            'filters' => ['status' => $status],
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, Task $task): Response
    {
        $this->ensureAssignee($request, $task);

        $task->load('creator:id,name');

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
            ->orderByDesc('id')
            ->get()
            ->map(fn (ModificationRequest $request) => [
                'id' => $request->id,
                'reason' => $request->reason,
                'status' => $request->status,
                'admin_response' => $request->admin_response,
                'created_at' => $request->created_at->toIso8601String(),
                'resolved_at' => $request->resolved_at?->toIso8601String(),
            ]);

        $hasOpenRequest = $requests->contains(fn (array $row) => $row['status'] === ModificationRequest::STATUS_OPEN);

        return Inertia::render('tasks/Show', [
            'task' => [
                ...$this->row($task),
                'description' => $task->description,
                'assigned_by' => $task->creator?->name,
                'accepted_at' => $task->accepted_at?->toIso8601String(),
                'submitted_at' => $task->submitted_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
            ],
            'events' => $events,
            'modification_requests' => $requests,
            'has_open_request' => $hasOpenRequest,
            'can' => [
                'accept' => $task->status === Task::STATUS_PENDING,
                'submit' => in_array($task->status, [Task::STATUS_ACCEPTED, Task::STATUS_CHANGES_REQUESTED], true),
                'request_modification' => ! $hasOpenRequest && in_array(
                    $task->status,
                    [Task::STATUS_PENDING, Task::STATUS_ACCEPTED, Task::STATUS_CHANGES_REQUESTED],
                    true,
                ),
            ],
        ]);
    }

    public function accept(Request $request, Task $task): RedirectResponse
    {
        $this->ensureAssignee($request, $task);

        return $this->perform($task, fn () => $this->workflow->accept($request->user(), $task));
    }

    public function submit(Request $request, Task $task): RedirectResponse
    {
        $this->ensureAssignee($request, $task);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->perform($task, fn () => $this->workflow->submit($request->user(), $task, $data['note'] ?? null));
    }

    public function requestModification(Request $request, Task $task): RedirectResponse
    {
        $this->ensureAssignee($request, $task);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->perform($task, fn () => $this->workflow->requestModification($request->user(), $task, $data['reason']));
    }

    public function comment(Request $request, Task $task): RedirectResponse
    {
        $this->ensureAssignee($request, $task);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->perform($task, fn () => $this->workflow->comment($request->user(), $task, $data['note']));
    }

    /**
     * A task that is not yours does not exist as far as you are concerned.
     */
    private function ensureAssignee(Request $request, Task $task): void
    {
        abort_unless($task->assigned_to === $request->user()->id, 404);
    }

    private function perform(Task $task, Closure $action): RedirectResponse
    {
        try {
            $action();
        } catch (TaskWorkflowException $exception) {
            return redirect("/tasks/{$task->id}")->withErrors(['task' => $exception->getMessage()]);
        }

        return redirect("/tasks/{$task->id}");
    }

    private function row(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'priority' => $task->priority,
            'status' => $task->status,
            'due_date' => $task->due_date->toDateString(),
            'days_until_due' => $task->daysUntilDue(),
            'is_overdue' => $task->isOverdue(),
        ];
    }
}