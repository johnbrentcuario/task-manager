<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to, $assignee] = $this->filters($request);

        $byUserAndStatus = $this->filtered($from, $to, $assignee)
            ->selectRaw('assigned_to, status, count(*) as total')
            ->groupBy('assigned_to', 'status')
            ->get();

        $overdueByUser = $this->filtered($from, $to, $assignee)
            ->overdue()
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $users = User::query()
            ->where('role', User::ROLE_USER)
            ->when($assignee, fn ($query) => $query->whereKey($assignee))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(function (User $user) use ($byUserAndStatus, $overdueByUser) {
                $row = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'is_active' => $user->is_active,
                    'total' => 0,
                ];

                foreach (Task::STATUSES as $status) {
                    $count = (int) $byUserAndStatus
                        ->where('assigned_to', $user->id)
                        ->where('status', $status)
                        ->sum('total');

                    $row[$status] = $count;
                    $row['total'] += $count;
                }

                $row['overdue'] = (int) ($overdueByUser[$user->id] ?? 0);
                $row['completion_rate'] = $row['total'] > 0
                    ? (int) round($row[Task::STATUS_COMPLETED] / $row['total'] * 100)
                    : null;

                return $row;
            })
            ->values();

        $totals = ['all' => 0];

        foreach (Task::STATUSES as $status) {
            $totals[$status] = (int) $byUserAndStatus->where('status', $status)->sum('total');
            $totals['all'] += $totals[$status];
        }

        $totals['overdue'] = (int) $overdueByUser->sum();

        $overdue = $this->filtered($from, $to, $assignee)
            ->overdue()
            ->with('assignee:id,name')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'assignee' => $task->assignee?->name,
                'status' => $task->status,
                'due_date' => $task->due_date->toDateString(),
                'days_overdue' => abs($task->daysUntilDue()),
            ]);

        return Inertia::render('admin/reports/Index', [
            'filters' => [
                'from' => $from,
                'to' => $to,
                'assignee' => $assignee,
            ],
            'totals' => $totals,
            'users' => $users,
            'overdue' => $overdue,
            'assignees' => User::query()
                ->where('role', User::ROLE_USER)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to, $assignee] = $this->filters($request);

        // Ordered by id only: chunking by id and sorting by anything else would skip rows.
        $query = $this->filtered($from, $to, $assignee)
            ->with('assignee:id,name')
            ->orderBy('id');

        $filename = 'tasks-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            // Byte-order mark, so Excel reads the file as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");

            $this->writeRow($out, [
                'ID', 'Title', 'Assigned to', 'Status', 'Priority', 'Deadline',
                'Overdue', 'Created', 'Accepted', 'Submitted', 'Completed',
            ]);

            $query->chunkById(200, function ($tasks) use ($out) {
                foreach ($tasks as $task) {
                    $this->writeRow($out, [
                        $task->id,
                        $task->title,
                        $task->assignee?->name,
                        ucfirst(str_replace('_', ' ', $task->status)),
                        ucfirst($task->priority),
                        $task->due_date->toDateString(),
                        $task->isOverdue() ? 'Yes' : 'No',
                        $task->created_at->format('Y-m-d H:i'),
                        $task->accepted_at?->format('Y-m-d H:i'),
                        $task->submitted_at?->format('Y-m-d H:i'),
                        $task->completed_at?->format('Y-m-d H:i'),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int}
     */
    private function filters(Request $request): array
    {
        $to = ['nullable', 'date'];

        if ($request->filled('from')) {
            $to[] = 'after_or_equal:from';
        }

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => $to,
            'assignee' => ['nullable', 'integer'],
        ]);

        return [
            $data['from'] ?? null,
            $data['to'] ?? null,
            isset($data['assignee']) ? (int) $data['assignee'] : null,
        ];
    }

    private function filtered(?string $from, ?string $to, ?int $assignee): Builder
    {
        return Task::query()
            ->when($from, fn ($query) => $query->whereDate('due_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('due_date', '<=', $to))
            ->when($assignee, fn ($query) => $query->where('assigned_to', $assignee));
    }

    /**
     * Spreadsheet programs run cells that start with = + - or @ as formulas,
     * so a task title could execute something. A leading apostrophe makes
     * them plain text.
     */
    private function writeRow($handle, array $row): void
    {
        $safe = array_map(function ($value) {
            if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'".$value;
            }

            return $value;
        }, $row);

        fputcsv($handle, $safe, ',', '"', '');
    }
}