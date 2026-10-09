<?php

namespace Tests\Feature;

use App\Exceptions\TaskWorkflowException;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TaskArchiveTest extends TestCase
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

    private function makeTask(User $admin, User $assignee, string $status = Task::STATUS_PENDING, array $extra = []): Task
    {
        return Task::factory()->create(array_merge([
            'created_by' => $admin->id,
            'assigned_to' => $assignee->id,
            'status' => $status,
        ], $extra));
    }

    // ---- Which tasks can be deleted for real ----

    public function test_an_untouched_pending_task_can_be_deleted(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create());

        TaskEvent::create([
            'task_id' => $task->id,
            'user_id' => $admin->id,
            'type' => TaskEvent::CREATED,
        ]);

        $this->assertTrue($this->workflow->isDeletable($task));

        $this->workflow->destroy($admin, $task);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseCount('task_events', 0);
    }

    public function test_edits_and_reassignments_do_not_make_a_task_undeletable(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $task = $this->workflow->create($admin, [
            'title' => 'Original',
            'priority' => 'medium',
            'due_date' => now()->addDays(3)->toDateString(),
            'assigned_to' => $first->id,
        ]);
        $task = $this->workflow->update($admin, $task, ['title' => 'Renamed']);
        $task = $this->workflow->update($admin, $task, ['assigned_to' => $second->id]);

        $this->assertTrue($this->workflow->isDeletable($task));
    }

    public function test_an_accepted_task_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create(), Task::STATUS_ACCEPTED);

        try {
            $this->workflow->destroy($admin, $task);
            $this->fail('Expected the delete to be refused.');
        } catch (TaskWorkflowException) {
            $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        }
    }

    public function test_a_pending_task_with_a_modification_request_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user);

        $this->workflow->requestModification($user, $task, 'Please clarify the scope.');

        $this->assertFalse($this->workflow->isDeletable($task));

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->destroy($admin, $task);
    }

    public function test_a_pending_task_with_a_comment_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user);

        $this->workflow->comment($user, $task, 'Question about the deadline.');

        $this->assertFalse($this->workflow->isDeletable($task));

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->destroy($admin, $task);
    }

    public function test_a_regular_user_cannot_delete_a_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user);

        $this->expectException(AuthorizationException::class);

        $this->workflow->destroy($user, $task);
    }

    // ---- Archive and restore ----

    public function test_a_regular_user_cannot_archive_a_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user);

        $this->expectException(AuthorizationException::class);

        $this->workflow->archive($user, $task);
    }

    public function test_a_regular_user_cannot_restore_a_task(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user);
        $this->workflow->archive($admin, $task);

        $this->expectException(AuthorizationException::class);

        $this->workflow->restore($user, $task);
    }

    public function test_archiving_hides_the_task_but_keeps_its_history(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user, Task::STATUS_ACCEPTED);

        $this->workflow->archive($admin, $task, 'No longer needed');

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertNull(Task::find($task->id));
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'user_id' => $admin->id,
            'type' => TaskEvent::ARCHIVED,
            'note' => 'No longer needed',
        ]);
    }

    public function test_a_completed_task_can_be_archived(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create(), Task::STATUS_COMPLETED);

        $this->workflow->archive($admin, $task);

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_restoring_an_archived_task_brings_it_back(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create(), Task::STATUS_ACCEPTED);

        $this->workflow->archive($admin, $task);
        $restored = $this->workflow->restore($admin, $task);

        $this->assertNotSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertSame(Task::STATUS_ACCEPTED, $restored->status);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'user_id' => $admin->id,
            'type' => TaskEvent::RESTORED,
        ]);
    }

    public function test_restoring_a_task_that_is_not_archived_fails(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create());

        $this->expectException(TaskWorkflowException::class);

        $this->workflow->restore($admin, $task);
    }

    public function test_an_archived_task_cannot_be_acted_on(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user);

        $this->workflow->archive($admin, $task);

        $this->expectException(ModelNotFoundException::class);

        $this->workflow->accept($user, $task);
    }

    // ---- Through the admin screens ----

    public function test_the_list_flags_which_tasks_can_be_deleted(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->makeTask($admin, $user, Task::STATUS_PENDING, ['title' => 'Fresh']);
        $this->makeTask($admin, $user, Task::STATUS_ACCEPTED, ['title' => 'Started']);

        $this->actingAs($admin)
            ->get('/admin/tasks')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 2)
                ->where('tasks.data', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['title'] === 'Fresh' && $row['can_delete'] === true
                ) && collect($rows)->contains(
                    fn ($row) => $row['title'] === 'Started' && $row['can_delete'] === false
                )));
    }

    public function test_an_untouched_task_can_be_deleted_from_the_list(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create());

        $this->actingAs($admin)
            ->delete("/admin/tasks/{$task->id}")
            ->assertRedirect('/admin/tasks');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_deleting_a_task_with_activity_is_blocked(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create(), Task::STATUS_ACCEPTED);

        $this->actingAs($admin)
            ->delete("/admin/tasks/{$task->id}")
            ->assertSessionHasErrors('task');

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_a_task_can_be_archived_from_the_list(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create(), Task::STATUS_ACCEPTED);

        $this->actingAs($admin)
            ->post("/admin/tasks/{$task->id}/archive")
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_archived_tasks_are_left_out_of_the_list_and_the_counts(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->makeTask($admin, $user, Task::STATUS_PENDING, ['title' => 'Visible']);
        $archived = $this->makeTask($admin, $user, Task::STATUS_ACCEPTED, ['title' => 'Hidden']);
        $this->workflow->archive($admin, $archived);

        $this->actingAs($admin)
            ->get('/admin/tasks')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Visible')
                ->where('counts.all', 1)
                ->where('counts.accepted', 0)
                ->where('counts.archived', 1));
    }

    public function test_the_archived_filter_shows_only_archived_tasks(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->makeTask($admin, $user, Task::STATUS_PENDING, ['title' => 'Visible']);
        $archived = $this->makeTask($admin, $user, Task::STATUS_ACCEPTED, ['title' => 'Hidden']);
        $this->workflow->archive($admin, $archived);

        $this->actingAs($admin)
            ->get('/admin/tasks?status=archived')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Hidden')
                ->where('tasks.data.0.is_archived', true)
                ->where('tasks.data.0.can_delete', false));
    }

    public function test_an_archived_task_can_be_restored_from_the_list(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create(), Task::STATUS_ACCEPTED);
        $this->workflow->archive($admin, $task);

        $this->actingAs($admin)
            ->post("/admin/tasks/{$task->id}/restore")
            ->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_an_archived_task_has_no_edit_page(): void
    {
        $admin = $this->admin();
        $task = $this->makeTask($admin, User::factory()->create());
        $this->workflow->archive($admin, $task);

        $this->actingAs($admin)
            ->get("/admin/tasks/{$task->id}/edit")
            ->assertNotFound();
    }

    public function test_regular_users_cannot_archive_or_restore_through_the_routes(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $task = $this->makeTask($admin, $user);
        $archived = $this->makeTask($admin, $user);
        $this->workflow->archive($admin, $archived);

        $this->actingAs($user)->post("/admin/tasks/{$task->id}/archive")->assertForbidden();
        $this->actingAs($user)->post("/admin/tasks/{$archived->id}/restore")->assertForbidden();

        $this->assertNotSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertSoftDeleted('tasks', ['id' => $archived->id]);
    }
}