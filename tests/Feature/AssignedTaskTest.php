<?php

namespace Tests\Feature;

use App\Models\ModificationRequest;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AssignedTaskTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $admin;
    }

    private function makeTask(User $assignee, string $status = Task::STATUS_PENDING, ?string $due = null, array $extra = []): Task
    {
        return Task::factory()->create(array_merge([
            'created_by' => $this->admin()->id,
            'assigned_to' => $assignee->id,
            'status' => $status,
            'due_date' => $due ?? now()->addDays(10)->toDateString(),
        ], $extra));
    }

    // ---- Access ----

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/tasks')->assertRedirect('/login');
        $this->get('/tasks/1')->assertRedirect('/login');
    }

    public function test_an_admin_is_sent_to_the_admin_task_list(): void
    {
        $this->actingAs($this->admin())->get('/tasks')->assertRedirect('/admin/tasks');
    }

    // ---- The list ----

    public function test_users_only_see_their_own_tasks(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->makeTask($user, Task::STATUS_PENDING, null, ['title' => 'Mine']);
        $this->makeTask($other, Task::STATUS_PENDING, null, ['title' => 'Theirs']);

        $this->actingAs($user)
            ->get('/tasks')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tasks/Index')
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Mine'));
    }

    public function test_the_default_list_hides_completed_tasks_and_history_shows_them(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user, Task::STATUS_ACCEPTED, null, ['title' => 'Open']);
        $this->makeTask($user, Task::STATUS_COMPLETED, null, ['title' => 'Done']);

        $this->actingAs($user)
            ->get('/tasks')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Open'));

        $this->actingAs($user)
            ->get('/tasks?status=completed')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Done'));
    }

    public function test_the_list_reports_counts(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user, Task::STATUS_PENDING);
        $this->makeTask($user, Task::STATUS_PENDING);
        $this->makeTask($user, Task::STATUS_ACCEPTED, now()->subDays(3)->toDateString());
        $this->makeTask($user, Task::STATUS_SUBMITTED);
        $this->makeTask($user, Task::STATUS_CHANGES_REQUESTED);
        $this->makeTask($user, Task::STATUS_COMPLETED);
        $this->makeTask($user, Task::STATUS_COMPLETED);
        $this->makeTask(User::factory()->create(), Task::STATUS_PENDING);

        $this->actingAs($user)
            ->get('/tasks')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.active', 5)
                ->where('counts.pending', 2)
                ->where('counts.accepted', 1)
                ->where('counts.submitted', 1)
                ->where('counts.changes_requested', 1)
                ->where('counts.completed', 2)
                ->where('counts.overdue', 1));
    }

    public function test_the_overdue_filter_shows_only_late_unfinished_tasks(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user, Task::STATUS_ACCEPTED, now()->subDays(2)->toDateString(), ['title' => 'Late']);
        $this->makeTask($user, Task::STATUS_COMPLETED, now()->subDays(2)->toDateString(), ['title' => 'Finished late']);
        $this->makeTask($user, Task::STATUS_PENDING, now()->addDays(2)->toDateString(), ['title' => 'Not due yet']);

        $this->actingAs($user)
            ->get('/tasks?status=overdue')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Late'));
    }

    public function test_rows_report_days_until_due(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user, Task::STATUS_ACCEPTED, now()->subDay()->toDateString(), ['title' => 'Late']);
        $this->makeTask($user, Task::STATUS_ACCEPTED, now()->addDay()->toDateString(), ['title' => 'Soon']);

        $this->actingAs($user)
            ->get('/tasks')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tasks.data', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['title'] === 'Late' && $row['days_until_due'] === -1 && $row['is_overdue'] === true
                ) && collect($rows)->contains(
                    fn ($row) => $row['title'] === 'Soon' && $row['days_until_due'] === 1 && $row['is_overdue'] === false
                )));
    }

    public function test_archived_tasks_are_hidden_from_the_list(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user)->delete();

        $this->actingAs($user)
            ->get('/tasks')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 0)
                ->where('counts.active', 0));
    }

    public function test_days_until_due_counts_calendar_days(): void
    {
        $user = User::factory()->create();

        $today = $this->makeTask($user, Task::STATUS_ACCEPTED, now()->toDateString());
        $tomorrow = $this->makeTask($user, Task::STATUS_ACCEPTED, now()->addDay()->toDateString());
        $late = $this->makeTask($user, Task::STATUS_ACCEPTED, now()->subDays(3)->toDateString());

        $this->assertSame(0, $today->fresh()->daysUntilDue());
        $this->assertSame(1, $tomorrow->fresh()->daysUntilDue());
        $this->assertSame(-3, $late->fresh()->daysUntilDue());
    }

    // ---- The task page ----

    public function test_the_assignee_can_open_a_task(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_PENDING);

        $this->actingAs($user)
            ->get("/tasks/{$task->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tasks/Show')
                ->where('task.id', $task->id)
                ->where('can.accept', true)
                ->where('can.submit', false)
                ->where('can.request_modification', true)
                ->where('has_open_request', false));
    }

    public function test_the_available_actions_depend_on_the_status(): void
    {
        $user = User::factory()->create();

        $expected = [
            Task::STATUS_PENDING => [true, false, true],
            Task::STATUS_ACCEPTED => [false, true, true],
            Task::STATUS_SUBMITTED => [false, false, false],
            Task::STATUS_CHANGES_REQUESTED => [false, true, true],
            Task::STATUS_COMPLETED => [false, false, false],
        ];

        foreach ($expected as $status => [$accept, $submit, $modify]) {
            $task = $this->makeTask($user, $status);

            $this->actingAs($user)
                ->get("/tasks/{$task->id}")
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('can.accept', $accept)
                    ->where('can.submit', $submit)
                    ->where('can.request_modification', $modify));
        }
    }

    public function test_an_open_modification_request_blocks_another_one(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        app(TaskWorkflow::class)->requestModification($user, $task, 'Need more time.');

        $this->actingAs($user)
            ->get("/tasks/{$task->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('has_open_request', true)
                ->where('can.request_modification', false)
                ->has('modification_requests', 1)
                ->where('modification_requests.0.status', ModificationRequest::STATUS_OPEN));
    }

    public function test_the_history_shows_the_administrators_feedback(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $workflow = app(TaskWorkflow::class);

        $task = $workflow->create($admin, [
            'title' => 'Prepare report',
            'priority' => 'medium',
            'due_date' => now()->addDays(5)->toDateString(),
            'assigned_to' => $user->id,
        ]);
        $task->forceFill(['status' => Task::STATUS_SUBMITTED])->save();
        $workflow->requestChanges($admin, $task, 'Please add the totals.');

        $this->actingAs($user)
            ->get("/tasks/{$task->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', 2)
                ->where('events.0.type', TaskEvent::CHANGES_REQUESTED)
                ->where('events.0.note', 'Please add the totals.')
                ->where('events.0.user_name', $admin->name)
                ->where('task.assigned_by', $admin->name));
    }

    public function test_other_users_cannot_see_or_act_on_a_task(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_PENDING);

        $this->actingAs($other)->get("/tasks/{$task->id}")->assertNotFound();
        $this->actingAs($other)->post("/tasks/{$task->id}/accept")->assertNotFound();
        $this->actingAs($other)->post("/tasks/{$task->id}/submit", ['note' => 'Mine now'])->assertNotFound();
        $this->actingAs($other)->post("/tasks/{$task->id}/modification-requests", ['reason' => 'Because'])->assertNotFound();
        $this->actingAs($other)->post("/tasks/{$task->id}/comments", ['note' => 'Hello'])->assertNotFound();

        $this->assertSame(Task::STATUS_PENDING, $task->fresh()->status);
        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('modification_requests', 0);
    }

    public function test_an_admin_who_is_not_the_assignee_gets_a_404(): void
    {
        $task = $this->makeTask(User::factory()->create());

        $this->actingAs($this->admin())->get("/tasks/{$task->id}")->assertNotFound();
    }

    public function test_an_archived_task_cannot_be_opened(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user);
        $task->delete();

        $this->actingAs($user)->get("/tasks/{$task->id}")->assertNotFound();
    }

    // ---- Actions ----

    public function test_the_assignee_can_accept_a_task(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_PENDING);

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/accept")
            ->assertRedirect("/tasks/{$task->id}");

        $this->assertSame(Task::STATUS_ACCEPTED, $task->fresh()->status);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'type' => TaskEvent::ACCEPTED,
        ]);
    }

    public function test_accepting_twice_is_refused(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/accept")
            ->assertSessionHasErrors('task');
    }

    public function test_the_assignee_can_submit_with_a_note(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/submit", ['note' => 'All done.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/tasks/{$task->id}");

        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'type' => TaskEvent::SUBMITTED,
            'note' => 'All done.',
        ]);
    }

    public function test_a_pending_task_cannot_be_submitted(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_PENDING);

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/submit", ['note' => 'Too early'])
            ->assertSessionHasErrors('task');

        $this->assertSame(Task::STATUS_PENDING, $task->fresh()->status);
    }

    public function test_a_task_can_be_resubmitted_after_changes_were_requested(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_CHANGES_REQUESTED);

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/submit", ['note' => 'Totals added.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Task::STATUS_SUBMITTED, $task->fresh()->status);
    }

    public function test_the_assignee_can_request_a_modification_with_a_reason(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/modification-requests", ['reason' => 'Need clarification.'])
            ->assertRedirect("/tasks/{$task->id}");

        $this->assertDatabaseHas('modification_requests', [
            'task_id' => $task->id,
            'requested_by' => $user->id,
            'reason' => 'Need clarification.',
            'status' => ModificationRequest::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'type' => TaskEvent::MODIFICATION_REQUESTED,
        ]);
    }

    public function test_a_modification_request_needs_a_reason(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/modification-requests", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('modification_requests', 0);
    }

    public function test_a_second_open_modification_request_is_refused(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        app(TaskWorkflow::class)->requestModification($user, $task, 'First reason.');

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/modification-requests", ['reason' => 'Second reason.'])
            ->assertSessionHasErrors('task');

        $this->assertDatabaseCount('modification_requests', 1);
    }

    public function test_the_assignee_can_comment(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/comments", ['note' => 'Working on it.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'type' => TaskEvent::COMMENTED,
            'note' => 'Working on it.',
        ]);
    }

    public function test_an_empty_comment_is_rejected(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, Task::STATUS_ACCEPTED);

        $this->actingAs($user)
            ->post("/tasks/{$task->id}/comments", ['note' => ''])
            ->assertSessionHasErrors('note');

        $this->assertDatabaseCount('task_events', 0);
    }
}