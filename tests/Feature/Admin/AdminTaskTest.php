<?php

namespace Tests\Feature\Admin;

use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminTaskTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $admin;
    }

    private function makeTask(User $admin, User $assignee, string $status = Task::STATUS_PENDING, ?string $due = null, array $extra = []): Task
    {
        return Task::factory()->create(array_merge([
            'created_by' => $admin->id,
            'assigned_to' => $assignee->id,
            'status' => $status,
            'due_date' => $due ?? now()->addDays(5)->toDateString(),
        ], $extra));
    }

    private function payload(User $assignee, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Prepare report',
            'description' => 'Quarterly numbers',
            'priority' => 'high',
            'due_date' => now()->addDays(7)->toDateString(),
            'assigned_to' => $assignee->id,
        ], $overrides);
    }

    // ---- Access ----

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/tasks')->assertRedirect('/login');
    }

    public function test_regular_users_cannot_reach_any_admin_task_page(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user);

        $this->actingAs($user)->get('/admin/tasks')->assertForbidden();
        $this->actingAs($user)->get('/admin/tasks/create')->assertForbidden();
        $this->actingAs($user)->post('/admin/tasks', $this->payload($user))->assertForbidden();
        $this->actingAs($user)->get("/admin/tasks/{$task->id}/edit")->assertForbidden();
        $this->actingAs($user)->put("/admin/tasks/{$task->id}", $this->payload($user, ['title' => 'Hacked']))->assertForbidden();
        $this->actingAs($user)->delete("/admin/tasks/{$task->id}")->assertForbidden();

        $this->assertDatabaseCount('tasks', 1);
        $this->assertNotSame('Hacked', $task->fresh()->title);
    }

    // ---- List, counts, filters ----

    public function test_an_admin_sees_every_task(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->makeTask($admin, $first);
        $this->makeTask($admin, $second);
        $this->makeTask($admin, $second);

        $this->actingAs($admin)
            ->get('/admin/tasks')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/tasks/Index')
                ->has('tasks.data', 3));
    }

    public function test_the_list_reports_counts_by_status_and_overdue(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->makeTask($admin, $user, Task::STATUS_PENDING);
        $this->makeTask($admin, $user, Task::STATUS_PENDING);
        $this->makeTask($admin, $user, Task::STATUS_COMPLETED);
        $this->makeTask($admin, $user, Task::STATUS_ACCEPTED, now()->subDays(3)->toDateString());

        $this->actingAs($admin)
            ->get('/admin/tasks')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.all', 4)
                ->where('counts.pending', 2)
                ->where('counts.accepted', 1)
                ->where('counts.submitted', 0)
                ->where('counts.changes_requested', 0)
                ->where('counts.completed', 1)
                ->where('counts.overdue', 1));
    }

    public function test_tasks_can_be_filtered_by_status(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->makeTask($admin, $user, Task::STATUS_SUBMITTED, null, ['title' => 'Waiting for review']);
        $this->makeTask($admin, $user, Task::STATUS_PENDING, null, ['title' => 'Not started']);

        $this->actingAs($admin)
            ->get('/admin/tasks?status=submitted')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Waiting for review'));
    }

    public function test_the_overdue_filter_excludes_completed_and_future_tasks(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->makeTask($admin, $user, Task::STATUS_ACCEPTED, now()->subDays(2)->toDateString(), ['title' => 'Late']);
        $this->makeTask($admin, $user, Task::STATUS_COMPLETED, now()->subDays(2)->toDateString(), ['title' => 'Finished late']);
        $this->makeTask($admin, $user, Task::STATUS_PENDING, now()->addDays(2)->toDateString(), ['title' => 'Not due yet']);

        $this->actingAs($admin)
            ->get('/admin/tasks?status=overdue')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Late')
                ->where('tasks.data.0.is_overdue', true));
    }

    public function test_tasks_can_be_filtered_by_assignee(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->makeTask($admin, $first, Task::STATUS_PENDING, null, ['title' => 'For first']);
        $this->makeTask($admin, $second, Task::STATUS_PENDING, null, ['title' => 'For second']);

        $this->actingAs($admin)
            ->get("/admin/tasks?assignee={$second->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'For second'));
    }

    public function test_tasks_can_be_searched(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->makeTask($admin, $user, Task::STATUS_PENDING, null, ['title' => 'Buy milk', 'description' => null]);
        $this->makeTask($admin, $user, Task::STATUS_PENDING, null, ['title' => 'Write report', 'description' => null]);

        $this->actingAs($admin)
            ->get('/admin/tasks?search=milk')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Buy milk'));
    }

    public function test_an_invalid_status_filter_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/tasks?status=banana')
            ->assertSessionHasErrors('status');
    }

    // ---- Create ----

    public function test_the_create_page_lists_only_active_regular_users(): void
    {
        $admin = $this->admin();
        $active = User::factory()->create();
        $inactive = User::factory()->create();
        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)
            ->get('/admin/tasks/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/tasks/Create')
                ->has('assignees', 1)
                ->where('assignees.0.id', $active->id));
    }

    public function test_an_admin_can_create_and_assign_a_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/tasks', $this->payload($user))
            ->assertRedirect('/admin/tasks');

        $task = Task::firstOrFail();

        $this->assertSame('Prepare report', $task->title);
        $this->assertSame(Task::STATUS_PENDING, $task->status);
        $this->assertSame($admin->id, $task->created_by);
        $this->assertSame($user->id, $task->assigned_to);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'user_id' => $admin->id,
            'type' => TaskEvent::CREATED,
        ]);
    }

    public function test_a_title_is_required(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/tasks', $this->payload($user, ['title' => '']))
            ->assertSessionHasErrors('title');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_new_task_cannot_have_a_past_deadline(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/tasks', $this->payload($user, ['due_date' => now()->subDay()->toDateString()]))
            ->assertSessionHasErrors('due_date');
    }

    public function test_an_invalid_priority_is_rejected(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/tasks', $this->payload($user, ['priority' => 'urgent']))
            ->assertSessionHasErrors('priority');
    }

    public function test_a_task_cannot_be_assigned_to_an_admin(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/tasks', $this->payload($otherAdmin))
            ->assertSessionHasErrors('assigned_to');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_task_cannot_be_assigned_to_a_deactivated_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)
            ->post('/admin/tasks', $this->payload($user))
            ->assertSessionHasErrors('assigned_to');
    }

    // ---- Edit ----

    public function test_an_admin_can_edit_a_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user, Task::STATUS_ACCEPTED);

        $this->actingAs($admin)
            ->put("/admin/tasks/{$task->id}", $this->payload($user, ['title' => 'Better title', 'priority' => 'low']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/tasks');

        $task->refresh();

        $this->assertSame('Better title', $task->title);
        $this->assertSame('low', $task->priority);
        $this->assertSame(Task::STATUS_ACCEPTED, $task->status);
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'type' => TaskEvent::EDITED]);
    }

    public function test_an_overdue_task_can_be_edited_without_changing_its_deadline(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $past = now()->subDays(4)->toDateString();
        $task = $this->makeTask($admin, $user, Task::STATUS_ACCEPTED, $past);

        $this->actingAs($admin)
            ->put("/admin/tasks/{$task->id}", $this->payload($user, ['title' => 'Still late', 'due_date' => $past]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Still late', $task->fresh()->title);
    }

    public function test_editing_keeps_a_deactivated_assignee(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user, Task::STATUS_ACCEPTED);
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)
            ->put("/admin/tasks/{$task->id}", $this->payload($user, ['title' => 'Edited while deactivated']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Edited while deactivated', $task->fresh()->title);
    }

    public function test_reassigning_sends_the_task_back_to_pending(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $task = $this->makeTask($admin, $first, Task::STATUS_ACCEPTED);

        $this->actingAs($admin)
            ->put("/admin/tasks/{$task->id}", $this->payload($second))
            ->assertSessionHasNoErrors();

        $task->refresh();

        $this->assertSame($second->id, $task->assigned_to);
        $this->assertSame(Task::STATUS_PENDING, $task->status);
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'type' => TaskEvent::REASSIGNED]);
    }

    public function test_a_completed_task_cannot_be_edited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user, Task::STATUS_COMPLETED, null, ['title' => 'Done']);

        $this->actingAs($admin)
            ->put("/admin/tasks/{$task->id}", $this->payload($user, ['title' => 'Changed']))
            ->assertSessionHasErrors('task');

        $this->assertSame('Done', $task->fresh()->title);
    }

    // ---- Delete ----

    public function test_an_admin_can_delete_a_task_and_its_history(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user);

        TaskEvent::create([
            'task_id' => $task->id,
            'user_id' => $admin->id,
            'type' => TaskEvent::CREATED,
        ]);

        $this->actingAs($admin)
            ->delete("/admin/tasks/{$task->id}")
            ->assertRedirect('/admin/tasks');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseCount('task_events', 0);
    }
}