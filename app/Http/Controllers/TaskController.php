<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
        ]);

        $search = $validated['search'] ?? null;
        $status = $validated['status'] ?? null;
        $priority = $validated['priority'] ?? null;
        $overdue = $request->boolean('overdue');

        $tasks = $request->user()
            ->tasks()
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($priority, fn ($query) => $query->where('priority', $priority))
            ->when($overdue, fn ($query) => $query
                ->whereDate('due_date', '<', now()->toDateString())
                ->where('status', '!=', 'done'))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('tasks/Index', [
            'tasks' => $tasks,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'priority' => $priority,
                'overdue' => $overdue,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->tasks()->create($this->validated($request));

        return back();
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->user_id === $request->user()->id, 403);

        $task->update($this->validated($request));

        return back();
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->user_id === $request->user()->id, 403);

        $task->delete();

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(Task::STATUSES)],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);
    }
}