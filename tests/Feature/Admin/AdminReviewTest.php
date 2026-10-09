<?php

namespace Tests\Feature\Admin;

use App\Models\ModificationRequest;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminReviewTest extends TestCase
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

    private function makeTask(User $assignee, string $status = Task::STATUS_PENDING, array $extra = []): Task
    {
        return Task::factory()->create(array_merge([
            'created_by' => $this->admin()->id,
            'assigned_to' => $assignee->id,
            'status' => $status,
            'due_date' => now()->addDays(10)->toDateString(),
        ], $extra));
    }

    // ---- Access ----

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/tasks/1')->assertRedirect('/login');
    }

    public function test_regular_users_cannot_reach_any_review_page_or_action(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_SUBMITTED);
        $open = $this->workflow->requestModification($user, $this->makeTask($user, Task::STATUS_ACCEPTED), 'Need time.');

        $this->actingAs($user)->get("/admin/tasks/{$task->id}")->assertForbidden();
        $this->actingAs($user)->post("/admin/tasks/{$task->id}/approve")->assertForbidden();
        $this->actingAs($user)->post("/admin/tasks/{$task->id}/request-changes", ['feedback' => 'No'])->assertForbidden();
        $this->actingAs($user)->post("/admin/tasks/{$task->id}/comments", ['note' => 'Hi'])->assertForbidden();
        $this->actingAs($user)->post("/admin/modification-requests/{$open->id}/approve")->assertForbidden();
        $this->actingAs($user)->post("/admin/modification-requests/{$open->id}/decline", ['response' => 'No'])->assertForbidden();

        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
        $this->assertSame(ModificationRequest::STATUS_OPEN, $open->fresh()->status);
    }

    // ---- The task page ----

    public function test_the_task_page_shows_details_and_the_latest_submission(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        $this->workflow->submit($user, $task, 'Finished.');

        $this->actingAs($admin)
            ->get("/admin/tasks/{$task->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/tasks/Show')
                ->where('task.id', $task->id)
                ->where('task.assignee', $user->name)
                ->where('task.status', Task::STATUS_SUBMITTED)
                ->where('is_archived', false)
                ->where('latest_submission.note', 'Finished.')
                ->where('latest_submission.user_name', $user->name)
                ->has('events', 1)
                ->where('can.review', true));
    }

    public function test_the_available_actions_depend_on_the_status(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $expected = [
            Task::STATUS_PENDING => [false, true],
            Task::STATUS_ACCEPTED => [false, true],
            Task::STATUS_SUBMITTED => [true, true],
            Task::STATUS_CHANGES_REQUESTED => [false, true],
            Task::STATUS_COMPLETED => [false, false],
        ];

        foreach ($expected as $status => [$review, $edit]) {
            $task = $this->makeTask($user, $status);

            $this->actingAs($admin)
                ->get("/admin/tasks/{$task->id}")
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('can.review', $review)
                    ->where('can.edit', $edit)
                    ->where('can.comment', true)
                    ->where('can.resolve_requests', true));
        }
    }

    public function test_the_task_page_lists_modification_requests_with_names(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        $this->workflow->requestModification($user, $task, 'Need more time.');

        $this->actingAs($admin)
            ->get("/admin/tasks/{$task->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('modification_requests', 1)
                ->where('modification_requests.0.status', ModificationRequest::STATUS_OPEN)
                ->where('modification_requests.0.requested_by', $user->name)
                ->where('modification_requests.0.reason', 'Need more time.'));
    }

    public function test_an_archived_task_can_be_viewed_but_not_acted_on(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask(User::factory()->create(), Task::STATUS_SUBMITTED);
        $task->delete();

        $this->actingAs($admin)
            ->get("/admin/tasks/{$task->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('is_archived', true)
                ->where('can.review', false)
                ->where('can.edit', false)
                ->where('can.comment', false)
                ->where('can.resolve_requests', false));
    }

    public function test_a_missing_task_is_a_404(): void
    {
        $this->actingAs($this->admin())->get('/admin/tasks/9999')->assertNotFound();
    }

    // ---- Approve and request changes ----

    public function test_an_admin_can_approve_a_submitted_task(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask(User::factory()->create(), Task::STATUS_SUBMITTED);

        $this->actingAs($admin)
            ->post("/admin/tasks/{$task->id}/approve", ['note' => 'Looks good.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/admin/tasks/{$task->id}");

        $task->refresh();

        $this->assertSame(Task::STATUS_COMPLETED, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'user_id' => $admin->id,
            'type' => TaskEvent::APPROVED,
            'note' => 'Looks good.',
        ]);
    }

    public function test_a_task_that_is_not_submitted_cannot_be_approved(): void
    {
        $task = $this->makeTask(User::factory()->create(), Task::STATUS_ACCEPTED);

        $this->actingAs($this->admin())
            ->post("/admin/tasks/{$task->id}/approve")
            ->assertSessionHasErrors('task');

        $this->assertSame(Task::STATUS_ACCEPTED, $task->fresh()->status);
    }

    public function test_an_archived_task_cannot_be_approved(): void
    {
        $task = $this->makeTask(User::factory()->create(), Task::STATUS_SUBMITTED);
        $task->delete();

        $this->actingAs($this->admin())
            ->post("/admin/tasks/{$task->id}/approve")
            ->assertNotFound();
    }

    public function test_requesting_changes_sends_the_task_back_with_feedback(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask(User::factory()->create(), Task::STATUS_SUBMITTED);

        $this->actingAs($admin)
            ->post("/admin/tasks/{$task->id}/request-changes", ['feedback' => 'Please add the totals.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/admin/tasks/{$task->id}");

        $this->assertSame(Task::STATUS_CHANGES_REQUESTED, $task->fresh()->status);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'user_id' => $admin->id,
            'type' => TaskEvent::CHANGES_REQUESTED,
            'note' => 'Please add the totals.',
        ]);
    }

    public function test_requesting_changes_needs_feedback(): void
    {
        $task = $this->makeTask(User::factory()->create(), Task::STATUS_SUBMITTED);

        $this->actingAs($this->admin())
            ->post("/admin/tasks/{$task->id}/request-changes", ['feedback' => ''])
            ->assertSessionHasErrors('feedback');

        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    // ---- Modification requests ----

    public function test_an_admin_can_approve_a_modification_request(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);
        $request = $this->workflow->requestModification($user, $task, 'Need more time.');

        $this->actingAs($admin)
            ->post("/admin/modification-requests/{$request->id}/approve", ['response' => 'Extended by a week.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/admin/tasks/{$task->id}");

        $request->refresh();

        $this->assertSame(ModificationRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($admin->id, $request->resolved_by);
        $this->assertSame('Extended by a week.', $request->admin_response);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'type' => TaskEvent::MODIFICATION_APPROVED,
        ]);
    }

    public function test_declining_a_modification_request_needs_a_reply(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);
        $request = $this->workflow->requestModification($user, $task, 'Need more time.');

        $this->actingAs($this->admin())
            ->post("/admin/modification-requests/{$request->id}/decline", ['response' => ''])
            ->assertSessionHasErrors('response');

        $this->assertSame(ModificationRequest::STATUS_OPEN, $request->fresh()->status);
    }

    public function test_an_admin_can_decline_a_modification_request_with_a_reply(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);
        $request = $this->workflow->requestModification($user, $task, 'Need more time.');

        $this->actingAs($this->admin())
            ->post("/admin/modification-requests/{$request->id}/decline", ['response' => 'The deadline stays.'])
            ->assertSessionHasNoErrors();

        $request->refresh();

        $this->assertSame(ModificationRequest::STATUS_DECLINED, $request->status);
        $this->assertSame('The deadline stays.', $request->admin_response);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'type' => TaskEvent::MODIFICATION_DECLINED,
        ]);
    }

    public function test_a_modification_request_cannot_be_resolved_twice(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);
        $request = $this->workflow->requestModification($user, $task, 'Need more time.');

        $this->actingAs($admin)->post("/admin/modification-requests/{$request->id}/approve");

        $this->actingAs($admin)
            ->post("/admin/modification-requests/{$request->id}/approve")
            ->assertSessionHasErrors('task');
    }

    public function test_a_modification_request_on_an_archived_task_is_a_404(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);
        $request = $this->workflow->requestModification($user, $task, 'Need more time.');
        $task->delete();

        $this->actingAs($this->admin())
            ->post("/admin/modification-requests/{$request->id}/approve")
            ->assertNotFound();

        $this->assertSame(ModificationRequest::STATUS_OPEN, $request->fresh()->status);
    }

    // ---- Comments ----

    public function test_an_admin_can_comment_on_a_task(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask(User::factory()->create(), Task::STATUS_ACCEPTED);

        $this->actingAs($admin)
            ->post("/admin/tasks/{$task->id}/comments", ['note' => 'Please keep me posted.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'user_id' => $admin->id,
            'type' => TaskEvent::COMMENTED,
            'note' => 'Please keep me posted.',
        ]);
    }

    public function test_an_empty_comment_is_rejected(): void
    {
        $task = $this->makeTask(User::factory()->create(), Task::STATUS_ACCEPTED);

        $this->actingAs($this->admin())
            ->post("/admin/tasks/{$task->id}/comments", ['note' => ''])
            ->assertSessionHasErrors('note');

        $this->assertDatabaseCount('task_events', 0);
    }
}