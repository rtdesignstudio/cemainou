<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Conectorii din aplicații (claude.ai, ChatGPT) intră pe /mcp prin OAuth: se înregistrează singuri, omul se loghează în cemainou și aprobă,
 * iar tokenul lucrează ca același om care are și chei de agent. Doar adresele de întoarcere știute; cheile OAuth stau criptate în setări.
 */
class OAuthConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    private function initialize(?string $token = null)
    {
        return $this->withToken((string) $token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'claude-ai', 'version' => '1']]]);
    }

    public function test_the_server_says_how_to_connect(): void
    {
        $this->initialize()->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('resource', url('/mcp'));
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_only_known_apps_can_register(): void
    {
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])->assertCreated()->assertJsonStructure(['client_id']);
        $this->postJson('/oauth/register', ['client_name' => 'Altcineva', 'redirect_uris' => ['https://evil.test/callback']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    }

    public function test_the_person_approves_and_the_app_works_as_them(): void
    {
        $radu = User::factory()->create(['name' => 'Radu Rusu', 'role' => 'admin']);
        $clientId = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])->json('client_id');
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = http_build_query(['client_id' => $clientId, 'redirect_uri' => self::CALLBACK, 'response_type' => 'code', 'scope' => 'mcp:use',
            'state' => 'abc', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']);

        $this->get('/oauth/authorize?'.$query)->assertRedirect(route('filament.admin.auth.login'));        // întâi logarea în cemainou

        $page = $this->actingAs($radu)->get('/oauth/authorize?'.$query)->assertOk()->assertSee(['Connect Claude', 'Radu Rusu', 'claude.ai', 'Allow', 'Deny']);
        preg_match('/name="auth_token" value="([^"]+)"/', $page->getContent(), $m);
        $back = $this->post(route('passport.authorizations.approve'), ['state' => 'abc', 'client_id' => $clientId, 'auth_token' => $m[1]]);
        parse_str(parse_url($back->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertStringStartsWith(self::CALLBACK, $back->headers->get('Location'));

        $token = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => self::CALLBACK,
            'code' => $params['code'], 'code_verifier' => $verifier])->assertOk()->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->initialize($token)->assertOk()->assertJsonPath('result.serverInfo.name', 'cemainou');
        $this->assertSame($radu->id, auth()->user()->id);                                                   // același om ca pe web și în Claude Code

        $project = Project::factory()->create(['name' => 'Site nou', 'lead_id' => $radu->id]);              // o sarcină din aplicația Claude: sursa e aplicația
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->withHeaders(['User-Agent' => 'Claude-User'])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'add_task', 'arguments' => ['project' => 'Site nou', 'title' => 'Pagina de contact']]])->assertOk();
        $this->assertSame('claude-app', Task::where('project_id', $project->id)->sole()->source->value);

        $stored = Setting::find('oauth_keys')->value;
        $this->assertStringNotContainsString('PRIVATE KEY', $stored['private']);                           // cheia privată, criptată
    }

    public function test_a_token_without_the_mcp_scope_or_an_inactive_account_gets_nothing(): void
    {
        Passport::actingAs($ion = User::factory()->create(), [], 'api');
        $this->initialize('x')->assertForbidden();

        Passport::actingAs($ion, ['mcp:use'], 'api');
        $this->initialize('x')->assertOk();

        $ion->update(['is_active' => false]);
        Passport::actingAs($ion->fresh(), ['mcp:use'], 'api');
        $this->initialize('x')->assertForbidden();
    }
}
