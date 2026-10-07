<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'priority' => fake()->randomElement(Task::PRIORITIES),
            'due_date' => fake()->dateTimeBetween('+1 day', '+1 month')->format('Y-m-d'),
            'status' => Task::STATUS_PENDING,
            'created_by' => User::factory(),
            'assigned_to' => User::factory(),
        ];
    }
}