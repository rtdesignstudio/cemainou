<?php

namespace App\Livewire;

use App\Enums\ActivityKind;
use App\Models\Activity;
use App\Models\McpCall;
use App\Support\AgentCommands;
use App\Support\ConnectCode;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token as OAuthToken;
use Livewire\Component;

/**
 * Cheile agenților unui om și aplicațiile lui, pe pagina de profil. Conectarea începe cu o singură întrebare, cu trei răspunsuri:
 * - „Lucrez cu un agent pe calculator" (computer, recomandat): o singură comandă pentru tot calculatorul (connect.sh caută Claude Code, aplicația
 *   desktop, Cursor, Codex, Gemini și îi leagă pe toți), cu un cod de unică folosință (10 minute); cheia se creează abia când comanda rulează.
 *   Comanda e gata imediat (calculatorul primește un nume automat), întâi ca mesaj de lipit în chat-ul agentului, apoi pentru terminal; dedesubt,
 *   trei bife care se fac verzi singure: cheia, prima sesiune (timpul se măsoară), uneltele folosite;
 * - „Vorbesc doar în chat" (chat): aplicația Claude sau ChatGPT, cu contul (OAuth, fără cheie), doar pașii aplicației alese;
 * - „Alt client MCP" (other): o cheie arătată o singură dată, cu adresa și antetele.
 * Un link ?connect=<loc> deschide fereastra direct la locul acela (computer pregătește comanda); ?connect=1, la întrebare.
 * Linkurile vechi (?connect=claude-code, cursor) duc la computer.
 */
class AgentTokens extends Component
{
    /** Locurile: computer (o cheie pe calculator, timpul măsurat), chat (alegerea aplicației), aplicațiile cu contul și alt client MCP. */
    public const TARGETS = ['computer', 'chat', 'claude-app', 'chatgpt', 'other'];

    public const APP_TARGETS = ['chat', 'claude-app', 'chatgpt'];

    /** Un client: aplicațiile și calculatorul (o cheie doar pentru MCP, fără hook-uri și fără timp), nu și alt client MCP. */
    public const CLIENT_TARGETS = ['computer', 'chat', 'claude-app', 'chatgpt'];

    /** Linkurile de dinainte de 0.1.341 (Comenzi, emailuri trimise): duc la noul loc. */
    private const LEGACY = ['claude-code' => 'computer', 'cursor' => 'computer'];

    /** Locul ales în fereastră; null = întrebarea. */
    public ?string $target = null;

    /** Venit cu ?connect=<loc>: fereastra se deschide singură. */
    public bool $openOnLoad = false;

    /** Comanda pentru tot calculatorul, abia creată: stă în pagină doar până se închide fereastra (forget). */
    public ?string $command = null;

    /** Numele cheii care se va crea (automat: „Calculator · 22 oct., 14:05"), ca fereastra s-o recunoască. */
    public ?string $connected = null;

    /** Când s-a dat comanda: de aici încolo fereastra caută cheia nouă, prima sesiune și primele unelte (fără reîncărcare). */
    public ?string $issuedAt = null;

    /** Cheia pentru alt client MCP: se arată o singură dată. */
    public ?string $plainToken = null;

    /** Un client: din aplicații, cu contul, sau de pe calculator cu o cheie (doar proiectele lui, fără ore). */
    public bool $forClient = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->connectsAgents(), 403);
        $this->forClient = Auth::user()->isClient();
        $target = request()->query('connect');
        if (is_string($target) && $target !== '') {
            $this->openOnLoad = true;                                                             // ?connect=1: întrebarea; ?connect=<loc>: direct la loc
            $this->pick(self::LEGACY[$target] ?? (in_array($target, $this->targets(), true) ? $target : null));
        }
    }

    public function pick(?string $target): void
    {
        $target = self::LEGACY[$target] ?? $target;
        abort_unless($target === null || in_array($target, $this->targets(), true), 400);
        $this->reset('command', 'connected', 'plainToken', 'issuedAt');
        $this->resetErrorBag();
        $this->target = $target;
        if ($target === 'computer' || $target === 'other') {
            $this->connect();                                                                     // fără pasul cu numele: comanda (sau cheia) e gata imediat
        }
    }

    /** „Înapoi": din aplicația aleasă, la alegerea aplicației; în rest, la întrebare. */
    public function back(): void
    {
        $this->pick(in_array($this->target, ['claude-app', 'chatgpt'], true) ? 'chat' : null);
    }

    /** Un loc pus direct din pagină (nu prin pick) trece prin aceeași verificare. */
    public function updatedTarget(): void
    {
        if ($this->target !== null && ! in_array($this->target, $this->targets(), true)) {
            $this->target = null;
        }
    }

    /** @return list<string> locurile pe care le poate alege omul: clientul doar aplicațiile */
    private function targets(): array
    {
        return $this->forClient ? self::CLIENT_TARGETS : self::TARGETS;
    }

    /** Pe calculator: comanda unică (computer) sau cheia (other), pe un nume automat de dispozitiv. */
    public function connect(): void
    {
        abort_unless(in_array($this->target, ['computer', 'other'], true) && Auth::user()->connectsAgents() && ($this->target === 'computer' || Auth::user()->usesAgents()), 403);
        $user = Auth::user();
        $this->connected = ($this->target === 'other' ? __('MCP client') : __('Computer')).' · '.now($user->tz())->locale(app()->getLocale())->isoFormat('D MMM, HH:mm');
        if ($this->target === 'other') {
            $this->plainToken = $user->createToken($this->connected, ['mcp'])->plainTextToken;   // doar pentru MCP; arătată o dată
        } else {
            $this->command = AgentCommands::connect(ConnectCode::issue($user, $this->connected), client: $user->isClient());
            $this->issuedAt = now()->toIso8601String();
        }
    }

    /** Fereastra s-a închis (Close, ×, Escape): comanda și cheia dispar din pagină. */
    public function forget(): void
    {
        $this->reset('command', 'connected', 'plainToken', 'target', 'openOnLoad', 'issuedAt');
    }

    /**
     * Cele trei bife ale conectării abia începute: key (comanda a rulat, cheia s-a creat), session (prima sesiune a ajuns: timpul se măsoară),
     * tools (agentul a folosit uneltele cemainou), plus fix: singurul pas de făcut acum, dacă una întârzie. null = nu e o conectare în curs.
     *
     * @return array{key: bool, session: bool, tools: bool, fix: ?string}|null
     */
    public function progress(): ?array
    {
        if (! $this->issuedAt || ! $this->command) {
            return null;
        }
        $token = Auth::user()->tokens()->where('name', $this->connected)->where('created_at', '>=', Carbon::parse($this->issuedAt)->subMinute())->latest('id')->first();
        if (! $token) {
            return ['key' => false, 'session' => false, 'tools' => false, 'fix' => Carbon::parse($this->issuedAt)->lt(now()->subMinutes(ConnectCode::MINUTES))
                ? __('The command expired after :minutes minutes: close this window and connect again.', ['minutes' => ConnectCode::MINUTES]) : null];
        }
        $session = $this->forClient                                                               // clientul n-are hook-uri: fără sesiuni, fără timp
            || Activity::where('user_id', Auth::id())->where('occurred_at', '>=', $token->created_at)->where('kind', '!=', ActivityKind::Mcp->value)->exists();
        $tools = McpCall::where('user_id', Auth::id())->where('created_at', '>=', $token->created_at)->whereNotIn('source', ['claude-app', 'chatgpt'])->exists();
        $fix = match (true) {
            ! $session && $tools => __('The agent uses cemainou, but no session signals arrive, so time is not measured: run the command again, as it is, then open a new session.'),
            ! $session => __('Open a new session in your agent (or restart the Claude app): the first one turns this on.'),
            ! $tools => __('Ask your agent something about your work, for example “what are my tasks?”. If it asks for permission to use cemainou, allow it.'),
            default => null,
        };

        return ['key' => true, 'session' => $session, 'tools' => $tools, 'fix' => $fix];
    }

    /** Rezultatul lui „Verifică acum": rânduri [stare ok|warn|bad, text], cel mai important primul. */
    public ?array $check = null;

    /**
     * „Verifică acum": ce ajunge de la agenții omului - sesiunile (hook-urile, cu cheia de pe calculator) și uneltele (cererile MCP) - și,
     * unde lipsește ceva, pasul care repară. Cel mai des: sesiunile ajung, dar uneltele nu (modul automat al Claude Code le blochează).
     */
    public function runCheck(): void
    {
        $user = Auth::user();
        $ago = fn ($at) => $at ? Carbon::parse($at)->diffForHumans() : null;
        $tokens = $user->tokens()->get();
        $hooks = Activity::where('user_id', $user->id)->whereIn('kind', [ActivityKind::SessionStart->value, ActivityKind::Prompt->value, ActivityKind::Tool->value])->max('occurred_at');
        $agentTools = McpCall::where('user_id', $user->id)->whereIn('source', ['claude-code', 'codex', 'cursor'])->max('created_at');
        $appTools = McpCall::where('user_id', $user->id)->whereIn('source', ['claude-app', 'chatgpt'])->max('created_at');
        $apps = OAuthToken::where('user_id', $user->id)->where('revoked', false)->where('created_at', '>', now()->subDays(60))->exists();
        $lines = [];

        if ($tokens->isEmpty()) {
            $lines[] = ['bad', __('No agent is connected on a computer yet: use “Connect an agent or app”.')];
        } elseif (! $hooks) {
            $lines[] = ['bad', __('The key exists, but no agent session has reached cemainou yet: run the connect command again in the computer\'s Terminal, then open a new session.')];
        } else {
            $old = Carbon::parse($hooks)->lt(now()->subDays(3));
            $lines[] = [$old ? 'warn' : 'ok', $old
                ? __('The last agent session reached cemainou :time. If you worked since, run the connect command again.', ['time' => $ago($hooks)])
                : __('Agent sessions reach cemainou: the last one :time.', ['time' => $ago($hooks)])];
            $viaConnector = $appTools && Carbon::parse($appTools)->gte(Carbon::parse($hooks)->subDays(1))
                && (! $agentTools || Carbon::parse($appTools)->gt(Carbon::parse($agentTools)));             // Claude Code din aplicația desktop: uneltele vin prin conectorul aplicației Claude
            if ($viaConnector) {
                $lines[] = ['ok', __('The agent uses the cemainou tools through the Claude app connector: the last time :time.', ['time' => $ago($appTools)])];
            } elseif (! $agentTools || Carbon::parse($agentTools)->lt(Carbon::parse($hooks)->subDays(1))) {
                $lines[] = ['warn', ($agentTools ? __('But the agent has not used the cemainou tools since :time.', ['time' => $ago($agentTools)]) : __('But the agent has never used the cemainou tools.'))
                    .' '.__('In auto mode, Claude Code may block them as an outside service: run the connect command again (it allows them), or approve them when it asks.')];
            } else {
                $lines[] = ['ok', __('The agent uses the cemainou tools: the last time :time.', ['time' => $ago($agentTools)])];
            }
        }
        if ($apps) {
            $lines[] = $appTools ? ['ok', __('The connected apps use cemainou: the last time :time.', ['time' => $ago($appTools)])]
                : ['warn', __('An app is connected, but has not used cemainou yet: in a new chat, ask it something about your work (“what tasks do I have?”).')];
        }
        $this->check = $lines;
    }

    public function revoke(int $id): void
    {
        Auth::user()->tokens()->whereKey($id)->delete();                // doar cheile proprii
    }

    public function revokeAll(): void
    {
        Auth::user()->tokens()->delete();
    }

    /** Deconectează o aplicație OAuth: toate tokenurile ei pentru omul acesta, cu tot cu reîmprospătarea; ca să revină, omul trebuie să aprobe din nou. */
    public function disconnect(string $clientId): void
    {
        $ids = OAuthToken::where('user_id', Auth::id())->where('client_id', $clientId)->pluck('id');
        OAuthToken::whereIn('id', $ids)->update(['revoked' => true]);
        RefreshToken::whereIn('access_token_id', $ids)->update(['revoked' => true]);
    }

    public function render(): View
    {
        return view('livewire.agent-tokens', [
            'tokens' => Auth::user()->tokens()->orderByDesc('last_used_at')->orderByDesc('id')->get(),
            // aplicațiile OAuth încă legate: un token nerevocat din ultimele 60 de zile (cât ține reîmprospătarea), unul pe aplicație
            'apps' => OAuthToken::with('client')->where('user_id', Auth::id())->where('revoked', false)->where('created_at', '>', now()->subDays(60))
                ->latest()->get()->unique('client_id')->values(),
            'mcpUrl' => AgentCommands::mcpUrl(),
            // clientul: calculatorul (cu rândul lui: doar proiectele lui) și aplicațiile, fără alt client MCP
            'places' => collect(AgentCommands::places())->when($this->forClient, fn ($c) => $c->where('key', '!=', 'other')->map(fn ($p) => isset($p['client_line']) ? ['line' => $p['client_line']] + $p : $p))->values()->all(),
            'idleDays' => Settings::get('token_idle_days'),
            'progress' => $this->progress(),
        ]);
    }
}
