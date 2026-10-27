<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Widgets\ConnectAgentHint;
use App\Livewire\AgentTokens;
use App\Models\User;
use App\Support\ConnectCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Profilul: fusul orar și limba fiecăruia, plus cheile agenților (create o singură dată, revocate doar de om). */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_sets_their_timezone_and_language(): void
    {
        $ana = User::factory()->create(['timezone' => 'Europe/Chisinau', 'locale' => 'en']);
        $this->actingAs($ana);

        Livewire::test(EditProfile::class)
            ->assertSee(['Agents', 'Timezone'])
            ->fillForm(['timezone' => 'Europe/Bucharest', 'locale' => 'ro'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Europe/Bucharest', 'ro'], [$ana->fresh()->timezone, $ana->fresh()->locale]);
    }

    public function test_connecting_an_agent_shows_the_key_once_in_a_ready_command(): void
    {
        $ana = User::factory()->create();
        $this->actingAs($ana);

        $component = Livewire::test(AgentTokens::class)->call('pick', 'computer')                 // comanda e gata imediat, fără pasul cu numele
            ->assertSee(['/agent/connect.sh | sh -s -- '.url('/')." 'wsc_", 'Connect this computer']);   // codul, nu cheia
        $this->assertSame(0, $ana->tokens()->count());
        preg_match('/wsc_[A-Za-z0-9]+/', $component->get('command'), $m);
        ConnectCode::redeem($m[0]);
        $this->assertStringStartsWith('Computer · ', $ana->tokens()->sole()->name);              // un nume automat, cu ziua și ora

        $component->call('pick', 'other');                                                        // alt client MCP: cheia, o dată
        $this->assertMatchesRegularExpression('/^\\d+\\|wsa_/', $component->get('plainToken'));    // prefixul o face recunoscută de scanerele de secrete
        $token = $ana->tokens()->latest('id')->first();
        $this->assertSame(['mcp'], $token->abilities);
        $this->assertStringStartsWith('MCP client · ', $token->name);

        $component->call('forget')->assertSet('plainToken', null)->assertDontSee('sh -s -- ');
    }

    public function test_people_revoke_only_their_own_keys(): void
    {
        $ana = User::factory()->create();
        $mine = $ana->createToken('Mac-ul Anei', ['mcp'])->accessToken;
        $theirs = User::factory()->create()->createToken('Laptopul lui Ion', ['mcp'])->accessToken;
        $this->actingAs($ana);

        Livewire::test(AgentTokens::class)->assertSee('Mac-ul Anei')->assertDontSee('Laptopul lui Ion')
            ->call('revoke', $theirs->id)
            ->call('revoke', $mine->id)
            ->assertDontSee('Mac-ul Anei');

        $this->assertModelMissing($mine);
        $this->assertModelExists($theirs);
    }

    public function test_today_reminds_people_without_an_agent_to_connect_one(): void
    {
        $ana = User::factory()->create();
        $this->actingAs($ana);
        $this->assertTrue(ConnectAgentHint::canView());

        $ana->createToken('MacBook', ['mcp']);
        $this->assertFalse(ConnectAgentHint::canView());
    }
}
