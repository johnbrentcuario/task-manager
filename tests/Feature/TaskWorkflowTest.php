<?php

namespace Tests\Feature;

use App\Exceptions\TaskWorkflowException;
use App\Models\ModificationRequest;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private TaskWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workflow = app(TaskWorkflow::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $admin;
    }

    private function taskFor(User $assignee, User $admin, string $status = Task::STATUS_PENDING): Task
    {
        return Task::factory()->create([
            'created_by' => $admin->id,
            'assigned_to' => $assignee->id,
            'status' => $status,
        ]);
    }

    private function assertEvent(Task $task, User $user, string $type, ?string $note = null): void
    {
        $this->assertDatabaseHas('task_events', array_filter([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'type' => $type,
            'note' => $note,
        ], fn ($value) => $value !== null));
    }

    // ---- Create ----

    public function test_an_admin_can_create_and_assign_a_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $task = $this->workflow->create($admin, [
            'title' => 'Prepare report',
            'description' => 'Quarterly numbers',
            'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
            'assigned_to' => $user->id,
        ]);

        $this->assertSame(Task::STATUS_PENDING, $task->status);
        $this->assertSame($admin->id, $task->created_by);
        $this->assertSame($user->id, $task->assigned_to);
        $this->assertEvent($task, $admin, TaskEvent::CREATED);
    }

    public function test_a_regular_user_cannot_create_a_task(): void
    {
        $user = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->workflow->create($user, [
            'title' => 'Nope',
            'priority' => 'low',
            'due_date' => now()->addDay()->toDateString(),
            'assigned_to' => $user->id,
        ]);
    }

    // ---- Accept ----

    public function test_the_assignee_can_accept_a_pending_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin);

        $task = $this->workflow->accept($user, $task);

        $this->assertSame(Task::STATUS_ACCEPTED, $task->status);
        $this->assertNotNull($task->accepted_at);
        $this->assertEvent($task, $user, TaskEvent::ACCEPTED);
    }

    public function test_someone_else_cannot_accept_the_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $task = $this->taskFor($user, $admin);

        $this->expectException(AuthorizationException::class);

        $this->workflow->accept($stranger, $task);
    }

    public function test_a_task_cannot_be_accepted_twice(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->accept($user, $task);
    }

    // ---- Submit ----

    public function test_the_assignee_can_submit_an_accepted_task_with_a_note(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $task = $this->workflow->submit($user, $task, 'All done, please review.');

        $this->assertSame(Task::STATUS_SUBMITTED, $task->status);
        $this->assertNotNull($task->submitted_at);
        $this->assertEvent($task, $user, TaskEvent::SUBMITTED, 'All done, please review.');
    }

    public function test_a_pending_task_cannot_be_submitted(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_PENDING);

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->submit($user, $task);
    }

    public function test_someone_else_cannot_submit_the_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $this->expectException(AuthorizationException::class);

        $this->workflow->submit($stranger, $task);
    }

    // ---- Approve and request changes ----

    public function test_an_admin_can_approve_a_submitted_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_SUBMITTED);

        $task = $this->workflow->approve($admin, $task, 'Looks good.');

        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertEvent($task, $admin, TaskEvent::APPROVED, 'Looks good.');
    }

    public function test_a_task_that_is_not_submitted_cannot_be_approved(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->approve($admin, $task);
    }

    public function test_a_regular_user_cannot_approve_a_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_SUBMITTED);

        $this->expectException(AuthorizationException::class);

        $this->workflow->approve($user, $task);
    }

    public function test_requesting_changes_sends_the_task_back_and_it_can_be_resubmitted(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_SUBMITTED);

        $task = $this->workflow->requestChanges($admin, $task, 'Please add the totals.');

        $this->assertSame(Task::STATUS_CHANGES_REQUESTED, $task->status);
        $this->assertEvent($task, $admin, TaskEvent::CHANGES_REQUESTED, 'Please add the totals.');

        $task = $this->workflow->submit($user, $task, 'Totals added.');

        $this->assertSame(Task::STATUS_SUBMITTED, $task->status);
    }

    public function test_requesting_changes_needs_feedback(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_SUBMITTED);

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->requestChanges($admin, $task, '   ');
    }

    // ---- Modification requests ----

    public function test_the_assignee_can_request_a_modification_with_a_reason(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $request = $this->workflow->requestModification($user, $task, 'The deadline is too tight.');

        $this->assertSame(ModificationRequest::STATUS_OPEN, $request->status);
        $this->assertSame('The deadline is too tight.', $request->reason);
        $this->assertEvent($task, $user, TaskEvent::MODIFICATION_REQUESTED, 'The deadline is too tight.');
    }

    public function test_a_modification_request_needs_a_reason(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->requestModification($user, $task, '');
    }

    public function test_only_one_modification_request_can_be_open_at_a_time(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $this->workflow->requestModification($user, $task, 'First reason.');

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->requestModification($user, $task, 'Second reason.');
    }

    public function test_a_submitted_task_cannot_get_a_modification_request(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_SUBMITTED);

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->requestModification($user, $task, 'Too late.');
    }

    public function test_someone_else_cannot_request_a_modification(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $this->expectException(AuthorizationException::class);

        $this->workflow->requestModification($stranger, $task, 'Not mine.');
    }

    public function test_an_admin_can_approve_a_modification_request(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);
        $request = $this->workflow->requestModification($user, $task, 'Need more time.');

        $request = $this->workflow->resolveModification($admin, $request, true, 'Extended by a week.');

        $this->assertSame(ModificationRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($admin->id, $request->resolved_by);
        $this->assertNotNull($request->resolved_at);
        $this->assertEvent($task, $admin, TaskEvent::MODIFICATION_APPROVED, 'Extended by a week.');
    }

    public function test_declining_a_modification_request_needs_a_response(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);
        $request = $this->workflow->requestModification($user, $task, 'Need more time.');

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->resolveModification($admin, $request, false, null);
    }

    public function test_a_modification_request_can_only_be_resolved_once(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);
        $request = $this->workflow->requestModification($user, $task, 'Need more time.');

        $this->workflow->resolveModification($admin, $request, false, 'Not possible.');

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->resolveModification($admin, $request, true);
    }

    public function test_a_regular_user_cannot_resolve_a_modification_request(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);
        $request = $this->workflow->requestModification($user, $task, 'Need more time.');

        $this->expectException(AuthorizationException::class);

        $this->workflow->resolveModification($user, $request, true);
    }

    // ---- Edit and reassign ----

    public function test_an_admin_can_edit_a_task_and_the_edit_is_recorded(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $task = $this->workflow->update($admin, $task, ['title' => 'A better title']);

        $this->assertSame('A better title', $task->title);
        $this->assertSame(Task::STATUS_ACCEPTED, $task->status);
        $this->assertEvent($task, $admin, TaskEvent::EDITED);
    }

    public function test_reassigning_a_task_resets_it_to_pending_for_the_new_assignee(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $task = $this->taskFor($first, $admin, Task::STATUS_ACCEPTED);
        $task->forceFill(['accepted_at' => now()])->save();

        $task = $this->workflow->update($admin, $task, ['assigned_to' => $second->id]);

        $this->assertSame($second->id, $task->assigned_to);
        $this->assertSame(Task::STATUS_PENDING, $task->status);
        $this->assertNull($task->accepted_at);
        $this->assertEvent($task, $admin, TaskEvent::REASSIGNED);
    }

    public function test_a_completed_task_cannot_be_edited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_COMPLETED);

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->update($admin, $task, ['title' => 'Too late']);
    }

    public function test_a_regular_user_cannot_edit_a_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin);

        $this->expectException(AuthorizationException::class);

        $this->workflow->update($user, $task, ['title' => 'Mine now']);
    }

    // ---- Comments ----

    public function test_the_assignee_and_an_admin_can_comment(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin, Task::STATUS_ACCEPTED);

        $this->workflow->comment($user, $task, 'Working on it.');
        $this->workflow->comment($admin, $task, 'Thanks for the update.');

        $this->assertEvent($task, $user, TaskEvent::COMMENTED, 'Working on it.');
        $this->assertEvent($task, $admin, TaskEvent::COMMENTED, 'Thanks for the update.');
    }

    public function test_a_stranger_cannot_comment(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $task = $this->taskFor($user, $admin);

        $this->expectException(AuthorizationException::class);

        $this->workflow->comment($stranger, $task, 'Hello.');
    }

    public function test_an_empty_comment_is_rejected(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->taskFor($user, $admin);

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->comment($user, $task, '  ');
    }
}