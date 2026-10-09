<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $admin;
    }

    private function makeTask(User $creator, User $assignee, string $status, string $due): Task
    {
        return Task::factory()->create([
            'created_by' => $creator->id,
            'assigned_to' => $assignee->id,
            'status' => $status,
            'due_date' => $due,
        ]);
    }

    public function test_the_admin_dashboard_shows_counts_and_the_review_queue(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $future = now()->addDays(5)->toDateString();

        $this->makeTask($admin, $user, Task::STATUS_SUBMITTED, $future);
        $this->makeTask($admin, $user, Task::STATUS_SUBMITTED, $future);
        $this->makeTask($admin, $user, Task::STATUS_PENDING, $future);
        $late = $this->makeTask($admin, $user, Task::STATUS_ACCEPTED, now()->subDays(3)->toDateString());

        app(TaskWorkflow::class)->requestModification($user, $late, 'Need more time.');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('member', null)
                ->where('admin.counts.submitted', 2)
                ->where('admin.counts.pending', 1)
                ->where('admin.counts.accepted', 1)
                ->where('admin.counts.overdue', 1)
                ->has('admin.review.submitted', 2)
                ->has('admin.review.requests', 1)
                ->where('admin.review.requests.0.task_title', $late->title));
    }

    public function test_resolved_requests_and_archived_tasks_leave_the_review_queue(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $future = now()->addDays(5)->toDateString();

        $archived = $this->makeTask($admin, $user, Task::STATUS_SUBMITTED, $future);
        $archived->delete();

        $accepted = $this->makeTask($admin, $user, Task::STATUS_ACCEPTED, $future);
        $request = app(TaskWorkflow::class)->requestModification($user, $accepted, 'Need more time.');
        app(TaskWorkflow::class)->resolveModification($admin, $request, true);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('admin.counts.submitted', 0)
                ->has('admin.review.submitted', 0)
                ->has('admin.review.requests', 0));
    }

    public function test_the_user_dashboard_shows_only_their_own_summary(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->makeTask($admin, $user, Task::STATUS_PENDING, now()->addDays(5)->toDateString());
        $this->makeTask($admin, $user, Task::STATUS_ACCEPTED, now()->subDays(2)->toDateString());
        $this->makeTask($admin, $user, Task::STATUS_COMPLETED, now()->subDays(9)->toDateString());
        $this->makeTask($admin, $other, Task::STATUS_PENDING, now()->addDays(1)->toDateString());

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('admin', null)
                ->where('member.counts.active', 2)
                ->where('member.counts.pending', 1)
                ->where('member.counts.completed', 1)
                ->where('member.counts.overdue', 1)
                ->has('member.upcoming', 2)
                ->where('member.upcoming.0.days_until_due', -2));
    }

    public function test_archived_tasks_are_left_out_of_the_user_dashboard(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->makeTask($admin, $user, Task::STATUS_PENDING, now()->addDays(5)->toDateString())->delete();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('member.counts.active', 0)
                ->has('member.upcoming', 0));
    }
}