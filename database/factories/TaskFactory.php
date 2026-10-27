<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'title' => ucfirst(fake()->words(4, true)), 'status' => 'todo', 'created_by' => User::factory(), 'source' => 'web'];
    }
}
