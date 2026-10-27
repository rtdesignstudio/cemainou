<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return ['client_id' => Client::factory(), 'name' => ucfirst(str_replace('-', ' ', $slug)), 'repo_url' => "git@github.com:firma/{$slug}.git"];
    }
}
