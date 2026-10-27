<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Când o pagină n-a primit răspuns de la server și apoi și-a revenit, adminul vede în Admin → Erori cât a durat și unde. */
class ClientUnreachableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_that_got_no_answer_is_noted_for_the_admin(): void
    {
        Log::shouldReceive('error')->once()->withArgs(fn ($message, $context) => $message === 'The server did not answer (HTTP 503) for 12 s' && $context['page'] === '/tasks');
        $this->actingAs(User::factory()->create(['role' => 'member']))
            ->postJson(route('client.unreachable'), ['status' => 503, 'seconds' => 12, 'page' => '/tasks'])->assertNoContent();
        $this->postJson(route('client.unreachable'), ['seconds' => 'x'])->assertUnprocessable();
    }
}
