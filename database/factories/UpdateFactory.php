<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UpdateFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'project_id' => Project::factory(), 'summary' => fake()->sentence(), 'source' => 'claude-code', 'period_to' => now()];
    }
}
