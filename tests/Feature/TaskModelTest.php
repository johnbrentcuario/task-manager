<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_task_belongs_to_a_creator_and_an_assignee(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $task = Task::factory()->create([
            'created_by' => $admin->id,
            'assigned_to' => $user->id,
        ]);

        $this->assertTrue($task->creator->is($admin));
        $this->assertTrue($task->assignee->is($user));
        $this->assertTrue($user->assignedTasks->contains($task));
        $this->assertTrue($admin->createdTasks->contains($task));
    }

    public function test_new_tasks_start_as_pending(): void
    {
        $task = Task::factory()->create();

        $this->assertSame(Task::STATUS_PENDING, $task->fresh()->status);
    }

    public function test_a_task_past_its_deadline_is_overdue(): void
    {
        $task = Task::factory()->create([
            'due_date' => now()->subDays(2)->toDateString(),
        ]);

        $this->assertTrue($task->isOverdue());
        $this->assertCount(1, Task::overdue()->get());
    }

    public function test_a_task_due_today_is_not_overdue(): void
    {
        $task = Task::factory()->create([
            'due_date' => now()->toDateString(),
        ]);

        $this->assertFalse($task->isOverdue());
        $this->assertCount(0, Task::overdue()->get());
    }

    public function test_a_completed_task_is_never_overdue(): void
    {
        $task = Task::factory()->create([
            'due_date' => now()->subDays(5)->toDateString(),
            'status' => Task::STATUS_COMPLETED,
        ]);

        $this->assertFalse($task->isOverdue());
        $this->assertCount(0, Task::overdue()->get());
    }

    public function test_deleting_a_task_deletes_its_history(): void
    {
        $task = Task::factory()->create();

        TaskEvent::create([
            'task_id' => $task->id,
            'user_id' => $task->created_by,
            'type' => TaskEvent::CREATED,
        ]);

        $task->delete();

        $this->assertDatabaseCount('task_events', 0);
    }

    public function test_users_are_active_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->fresh()->is_active);
    }
}