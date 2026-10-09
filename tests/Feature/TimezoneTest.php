<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_uses_philippine_time(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame('Asia/Manila', now()->getTimezone()->getName());
    }

    public function test_a_task_due_today_is_not_overdue_late_in_the_evening(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(23, 30));

        $task = Task::factory()->create([
            'status' => Task::STATUS_ACCEPTED,
            'due_date' => now()->toDateString(),
        ])->fresh();

        $this->assertFalse($task->isOverdue());
        $this->assertSame(0, $task->daysUntilDue());
        $this->assertCount(0, Task::overdue()->get());
    }

    public function test_the_same_task_is_overdue_just_after_midnight(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(23, 30));

        $task = Task::factory()->create([
            'status' => Task::STATUS_ACCEPTED,
            'due_date' => now()->toDateString(),
        ]);

        $this->travelTo(now()->addHour());

        $task = $task->fresh();

        $this->assertTrue($task->isOverdue());
        $this->assertSame(-1, $task->daysUntilDue());
        $this->assertCount(1, Task::overdue()->get());
    }
}