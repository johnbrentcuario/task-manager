<?php

namespace App\Http\Controllers;

use App\Models\ModificationRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'admin' => $user->isAdmin() ? $this->adminSummary() : null,
            'member' => $user->isAdmin() ? null : $this->memberSummary($user),
        ]);
    }

    private function adminSummary(): array
    {
        $counts = $this->statusCounts(Task::query());
        $counts['overdue'] = Task::overdue()->count();

        $submitted = Task::query()
            ->with('assignee:id,name')
            ->where('status', Task::STATUS_SUBMITTED)
            ->orderBy('submitted_at')
            ->limit(10)
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'assignee' => $task->assignee?->name,
                'submitted_at' => $task->submitted_at?->toIso8601String(),
            ]);

        $requests = ModificationRequest::query()
            ->where('status', ModificationRequest::STATUS_OPEN)
            ->whereHas('task')
            ->with(['task:id,title', 'requester:id,name'])
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->map(fn (ModificationRequest $request) => [
                'id' => $request->id,
                'task_id' => $request->task_id,
                'task_title' => $request->task->title,
                'requester' => $request->requester?->name,
                'reason' => $request->reason,
                'created_at' => $request->created_at->toIso8601String(),
            ]);

        return [
            'counts' => $counts,
            'review' => [
                'submitted' => $submitted,
                'requests' => $requests,
            ],
        ];
    }

    private function memberSummary(User $user): array
    {
        $mine = fn () => Task::query()->where('assigned_to', $user->id);

        $counts = $this->statusCounts($mine());
        $counts['active'] = array_sum($counts) - $counts[Task::STATUS_COMPLETED];
        $counts['overdue'] = $mine()->overdue()->count();

        $upcoming = $mine()
            ->where('status', '!=', Task::STATUS_COMPLETED)
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date->toDateString(),
                'days_until_due' => $task->daysUntilDue(),
            ]);

        return [
            'counts' => $counts,
            'upcoming' => $upcoming,
        ];
    }

    private function statusCounts(Builder $query): array
    {
        $byStatus = $query
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (Task::STATUSES as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        return $counts;
    }
}