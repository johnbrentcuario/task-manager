<?php

namespace Tests\Feature\Admin;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReportTest extends TestCase
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
        $this->get('/admin/reports')->assertRedirect('/login');
        $this->get('/admin/reports/export')->assertRedirect('/login');
    }

    public function test_regular_users_cannot_see_or_export_reports(): void
    {
        $user = User::factory()->create();
        $this->makeTask($user);

        $this->actingAs($user)->get('/admin/reports')->assertForbidden();
        $this->actingAs($user)->get('/admin/reports/export')->assertForbidden();
    }

    // ---- The report ----

    public function test_the_totals_count_every_status_and_overdue(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user, Task::STATUS_PENDING);
        $this->makeTask($user, Task::STATUS_PENDING);
        $this->makeTask($user, Task::STATUS_ACCEPTED, now()->subDays(3)->toDateString());
        $this->makeTask($user, Task::STATUS_COMPLETED);

        $this->actingAs($this->admin())
            ->get('/admin/reports')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/reports/Index')
                ->where('totals.all', 4)
                ->where('totals.pending', 2)
                ->where('totals.accepted', 1)
                ->where('totals.submitted', 0)
                ->where('totals.completed', 1)
                ->where('totals.overdue', 1));
    }

    public function test_each_user_has_a_progress_row_with_a_completion_rate(): void
    {
        $busy = User::factory()->create();
        $idle = User::factory()->create();

        $this->makeTask($busy, Task::STATUS_COMPLETED);
        $this->makeTask($busy, Task::STATUS_PENDING);

        $this->actingAs($this->admin())
            ->get('/admin/reports')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('users', 2)
                ->where('users', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['id'] === $busy->id
                        && $row['total'] === 2
                        && $row['completed'] === 1
                        && $row['pending'] === 1
                        && $row['completion_rate'] === 50
                ) && collect($rows)->contains(
                    fn ($row) => $row['id'] === $idle->id
                        && $row['total'] === 0
                        && $row['completion_rate'] === null
                )));
    }

    public function test_the_deadline_range_limits_the_report(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user, Task::STATUS_PENDING, now()->addDays(2)->toDateString());
        $this->makeTask($user, Task::STATUS_PENDING, now()->addDays(20)->toDateString());

        $from = now()->toDateString();
        $to = now()->addDays(5)->toDateString();

        $this->actingAs($this->admin())
            ->get("/admin/reports?from={$from}&to={$to}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('totals.all', 1)
                ->where('filters.from', $from)
                ->where('filters.to', $to));
    }

    public function test_the_user_filter_limits_the_report(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->makeTask($first);
        $this->makeTask($second);
        $this->makeTask($second);

        $this->actingAs($this->admin())
            ->get("/admin/reports?assignee={$second->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('totals.all', 2)
                ->has('users', 1)
                ->where('users.0.id', $second->id));
    }

    public function test_the_overdue_list_shows_the_most_overdue_first_and_skips_completed_tasks(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user, Task::STATUS_ACCEPTED, now()->subDays(2)->toDateString(), ['title' => 'Slightly late']);
        $this->makeTask($user, Task::STATUS_ACCEPTED, now()->subDays(6)->toDateString(), ['title' => 'Very late']);
        $this->makeTask($user, Task::STATUS_COMPLETED, now()->subDays(9)->toDateString(), ['title' => 'Finished late']);

        $this->actingAs($this->admin())
            ->get('/admin/reports')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('overdue', 2)
                ->where('overdue.0.title', 'Very late')
                ->where('overdue.0.days_overdue', 6)
                ->where('overdue.1.title', 'Slightly late'));
    }

    public function test_archived_tasks_are_left_out_of_the_report(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user);
        $this->makeTask($user)->delete();

        $this->actingAs($this->admin())
            ->get('/admin/reports')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('totals.all', 1));
    }

    public function test_a_range_that_ends_before_it_starts_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/reports?from=2026-10-10&to=2026-10-01')
            ->assertSessionHasErrors('to');
    }

    // ---- The CSV export ----

    public function test_the_export_downloads_a_csv_with_one_row_per_task(): void
    {
        $user = User::factory()->create(['name' => 'Report Person']);

        $this->makeTask($user, Task::STATUS_ACCEPTED, now()->addDays(4)->toDateString(), ['title' => 'First task']);
        $this->makeTask($user, Task::STATUS_COMPLETED, now()->subDays(1)->toDateString(), ['title' => 'Second task']);

        $response = $this->actingAs($this->admin())->get('/admin/reports/export');

        $response->assertOk()->assertDownload('tasks-'.now()->format('Y-m-d').'.csv');

        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Assigned to', $content);
        $this->assertStringContainsString('First task', $content);
        $this->assertStringContainsString('Second task', $content);
        $this->assertStringContainsString('Report Person', $content);
        $this->assertStringContainsString('Accepted', $content);
        $this->assertStringContainsString('Completed', $content);
        $this->assertSame(3, count(array_filter(explode("\n", trim($content)))));
    }

    public function test_the_export_follows_the_filters(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->makeTask($first, Task::STATUS_PENDING, null, ['title' => 'Belongs to the first']);
        $this->makeTask($second, Task::STATUS_PENDING, null, ['title' => 'Belongs to the second']);

        $content = $this->actingAs($this->admin())
            ->get("/admin/reports/export?assignee={$second->id}")
            ->streamedContent();

        $this->assertStringContainsString('Belongs to the second', $content);
        $this->assertStringNotContainsString('Belongs to the first', $content);
    }

    public function test_the_export_neutralises_spreadsheet_formulas(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user, Task::STATUS_PENDING, null, ['title' => '=HYPERLINK("http://example.test","click")']);

        $content = $this->actingAs($this->admin())->get('/admin/reports/export')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK(", $content);
        $this->assertStringNotContainsString(',"=HYPERLINK(', $content);
        $this->assertStringNotContainsString(',=HYPERLINK(', $content);
    }

    public function test_the_export_leaves_out_archived_tasks(): void
    {
        $user = User::factory()->create();

        $this->makeTask($user, Task::STATUS_PENDING, null, ['title' => 'Still here']);
        $this->makeTask($user, Task::STATUS_PENDING, null, ['title' => 'Archived away'])->delete();

        $content = $this->actingAs($this->admin())->get('/admin/reports/export')->streamedContent();

        $this->assertStringContainsString('Still here', $content);
        $this->assertStringNotContainsString('Archived away', $content);
    }
}