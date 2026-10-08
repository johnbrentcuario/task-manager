<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TaskWorkflowException;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function __construct(private TaskWorkflow $workflow)
    {
    }

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...Task::STATUSES, 'overdue'])],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'assignee' => ['nullable', 'integer'],
        ]);

        $search = $validated['search'] ?? null;
        $status = $validated['status'] ?? null;
        $priority = $validated['priority'] ?? null;
        $assignee = $validated['assignee'] ?? null;

        $tasks = Task::query()
            ->with('assignee:id,name')
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->when($status === 'overdue', fn ($query) => $query->overdue())
            ->when($status && $status !== 'overdue', fn ($query) => $query->where('status', $status))
            ->when($priority, fn ($query) => $query->where('priority', $priority))
            ->when($assignee, fn ($query) => $query->where('assigned_to', $assignee))
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'priority' => $task->priority,
                'status' => $task->status,
                'due_date' => $task->due_date->toDateString(),
                'is_overdue' => $task->isOverdue(),
                'assignee' => $task->assignee?->name,
            ]);

        $byStatus = Task::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = ['all' => (int) $byStatus->sum()];

        foreach (Task::STATUSES as $name) {
            $counts[$name] = (int) ($byStatus[$name] ?? 0);
        }

        $counts['overdue'] = Task::overdue()->count();

        return Inertia::render('admin/tasks/Index', [
            'tasks' => $tasks,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'priority' => $priority,
                'assignee' => $assignee,
            ],
            'counts' => $counts,
            'users' => User::query()
                ->where('role', User::ROLE_USER)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/tasks/Create', [
            'assignees' => $this->assignees(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(null));

        $this->workflow->create($request->user(), $data);

        return redirect('/admin/tasks');
    }

    public function edit(Task $task): Response
    {
        return Inertia::render('admin/tasks/Edit', [
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'priority' => $task->priority,
                'status' => $task->status,
                'due_date' => $task->due_date->toDateString(),
                'assigned_to' => $task->assigned_to,
            ],
            'assignees' => $this->assignees($task),
        ]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate($this->rules($task));

        try {
            $this->workflow->update($request->user(), $task, $data);
        } catch (TaskWorkflowException $exception) {
            return back()->withErrors(['task' => $exception->getMessage()]);
        }

        return redirect('/admin/tasks');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $task->delete();

        return redirect('/admin/tasks');
    }

    /**
     * Validation rules. On create, the deadline can not be in the past.
     * On edit, past dates are allowed so overdue tasks can still be edited,
     * and a task's current assignee is accepted even if deactivated.
     */
    private function rules(?Task $task): array
    {
        $dueDate = ['required', 'date'];

        if ($task === null) {
            $dueDate[] = 'after_or_equal:today';
        }

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'due_date' => $dueDate,
            'assigned_to' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($task) {
                if ($task !== null && (int) $value === $task->assigned_to) {
                    return;
                }

                $allowed = User::query()
                    ->whereKey($value)
                    ->where('role', User::ROLE_USER)
                    ->where('is_active', true)
                    ->exists();

                if (! $allowed) {
                    $fail('Choose an active user.');
                }
            }],
        ];
    }

    private function assignees(?Task $task = null)
    {
        return User::query()
            ->where('role', User::ROLE_USER)
            ->where(function ($query) use ($task) {
                $query->where('is_active', true);

                if ($task !== null) {
                    $query->orWhere('id', $task->assigned_to);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
    }
}