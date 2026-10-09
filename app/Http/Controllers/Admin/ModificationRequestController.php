<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TaskWorkflowException;
use App\Http\Controllers\Controller;
use App\Models\ModificationRequest;
use App\Services\TaskWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ModificationRequestController extends Controller
{
    public function __construct(private TaskWorkflow $workflow)
    {
    }

    public function approve(Request $request, ModificationRequest $modificationRequest): RedirectResponse
    {
        $data = $request->validate([
            'response' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->resolve($request, $modificationRequest, true, $data['response'] ?? null);
    }

    public function decline(Request $request, ModificationRequest $modificationRequest): RedirectResponse
    {
        $data = $request->validate([
            'response' => ['required', 'string', 'max:2000'],
        ]);

        return $this->resolve($request, $modificationRequest, false, $data['response']);
    }

    private function resolve(Request $request, ModificationRequest $modificationRequest, bool $approve, ?string $response): RedirectResponse
    {
        // The task relation skips archived tasks, so a request on an archived task is "not found".
        $task = $modificationRequest->task;

        abort_if($task === null, 404);

        try {
            $this->workflow->resolveModification($request->user(), $modificationRequest, $approve, $response);
        } catch (TaskWorkflowException $exception) {
            return redirect("/admin/tasks/{$task->id}")->withErrors(['task' => $exception->getMessage()]);
        }

        return redirect("/admin/tasks/{$task->id}");
    }
}