<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Setările sistemului, într-un singur loc: valoarea implicită (din config / .env), regulile și valoarea aleasă de admin în pagina Setări
 * (tabelul settings). Se citesc o dată pe cerere (singleton). Înainte de migrare (la instalare) se folosesc valorile implicite.
 */
class Settings
{
    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    /** @var array<string, array>|null definițiile, construite o dată pe cerere (instanța e singleton) */
    private ?array $definitions = null;

    /**
     * @return array<string, array{default: mixed, rules: array, secret?: bool}> în ordinea de pe pagină; cele „secret" se păstrează criptate (APP_KEY).
     *                                                                           Construite o singură dată pe cerere: se citesc de zeci de ori, iar lista cu regulile ei costa ~15 ms la fiecare citire.
     */
    public static function definitions(): array
    {
        $settings = app(self::class);

        return $settings->definitions ??= self::build();
    }

    private static function build(): array
    {
        $int = fn (int $min, int $max) => ['required', 'integer', "min:{$min}", "max:{$max}"];

        return [
            // compania: numele și logo-ul (calea pe discul data), pe Azi în locul butonului „Sarcină nouă"
            'company_name' => ['default' => null, 'rules' => ['nullable', 'string', 'max:100']],
            'company_logo' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255']],
            // pagina Confidențialitate (/privacy): cine răspunde de date, adresa, emailul pentru cereri; publică sau doar pentru cei logați
            'legal_name' => ['default' => null, 'rules' => ['nullable', 'string', 'max:150']],
            'legal_address' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255']],
            'privacy_email' => ['default' => null, 'rules' => ['nullable', 'email', 'max:255']],
            'privacy_public' => ['default' => true, 'rules' => ['boolean']],
            'locale' => ['default' => 'en', 'rules' => ['required', Rule::in(array_keys(SetLocale::LOCALES))]],
            'timezone' => ['default' => config('cemainou.timezone'), 'rules' => ['required', 'timezone:all']],
            'gap_minutes' => ['default' => 15, 'rules' => $int(5, 60)],
            'workday_hours' => ['default' => 8, 'rules' => $int(4, 12)],                                       // o zi de lucru, pentru totaluri în zile
            'report_days' => ['default' => 14, 'rules' => $int(1, 1000)],
            'history_days' => ['default' => 600, 'rules' => $int(7, 1000)],
            'max_upload_mb' => ['default' => 20, 'rules' => $int(1, 100)],
            'allow_html' => ['default' => true, 'rules' => ['boolean']],
            'mcp_per_minute' => ['default' => (int) config('cemainou.mcp_per_minute'), 'rules' => $int(10, 2000)],
            'mcp_ip_per_minute' => ['default' => (int) config('cemainou.mcp_ip_per_minute'), 'rules' => $int(10, 5000)],
            'clients_use_agents' => ['default' => false, 'rules' => ['boolean']],      // clienții își pot conecta agentul (din aplicație, cu contul), pe proiectele lor
            'token_idle_days' => ['default' => (int) config('cemainou.token_idle_days'), 'rules' => $int(7, 365)],
            'keep_raw_days' => ['default' => (int) config('cemainou.keep_raw_days'), 'rules' => $int(30, 730)],
            'password_min' => ['default' => 12, 'rules' => $int(8, 64)],
            // autentificarea în doi pași: oprită de tot până o pornește adminul; apoi fiecare o activează din profil, iar adminilor li se poate cere
            'two_factor_enabled' => ['default' => false, 'rules' => ['boolean']],
            'two_factor_admins' => ['default' => false, 'rules' => ['boolean']],
            // backup-ul: zilnic, la curățenie; câte se păstrează
            'backup_daily' => ['default' => true, 'rules' => ['boolean']],
            // badge-urile: pornite pentru toată echipa (fiecare le poate ascunde din profil); animația pe tot ecranul la unul nou
            'badges_on' => ['default' => true, 'rules' => ['boolean']],
            'jokes_on' => ['default' => true, 'rules' => ['boolean']],
            'music_on' => ['default' => false, 'rules' => ['boolean']],
            'music_players' => ['default' => [], 'rules' => ['array'], 'items' => ['integer']],   // cine poate porni muzica la el (adminii pot oricum)                    // Muzica biroului (App\Support\Jukebox): tonomatul pe YouTube                     // gluma zilei pe Azi (App\Support\Jokes)
            'badges_animation' => ['default' => true, 'rules' => ['boolean']],
            'update_check' => ['default' => true, 'rules' => ['boolean']],
            // feedback-ul despre cemainou trimis și la RT (fără numele sau emailul omului); oprit până alege adminul
            'feedback_to_rt' => ['default' => false, 'rules' => ['boolean']],
            // „Ține-mă la curent": instalarea se face cunoscută la RT (emailul, firma, versiunea), doar dacă adminul o pornește; altfel nu trimite nimic
            'keep_in_touch' => ['default' => false, 'rules' => ['boolean']],
            'keep_in_touch_email' => ['default' => null, 'rules' => ['nullable', 'email', 'max:255', 'required_if_accepted:data.keep_in_touch']],
            'keep_in_touch_company' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255']],
            // cheia de susținător: semnată de RT, nu deblochează nimic (doar „Susținător"); o cheie greșită nu se salvează
            'license_key' => ['default' => null, 'rules' => ['nullable', 'string', 'max:2000', fn (string $attribute, mixed $value, \Closure $fail) => blank($value) || License::read($value) !== null ?: $fail(__('This is not a cemainou license key.'))]],
            'backup_keep' => ['default' => 7, 'rules' => ['required', 'integer', 'min:1', 'max:60']],
            // rezumatul săptămânal pe email: ziua (1 = luni ... 7 = duminică; gol = oprit) și ora, în fusul echipei
            'digest_day' => ['default' => 1, 'rules' => ['nullable', 'integer', 'min:1', 'max:7']],
            'digest_hour' => ['default' => 8, 'rules' => ['required', 'integer', 'min:0', 'max:23']],
            // canalele echipei: fiecare update publicat (neintern) ajunge în Slack și / sau Telegram
            'slack_webhook' => ['default' => null, 'rules' => ['nullable', 'url:https', 'max:500'], 'secret' => true],
            'telegram_bot_token' => ['default' => null, 'rules' => ['nullable', 'string', 'max:100', 'regex:/^\d+:[\w-]+$/'], 'secret' => true],
            'telegram_chat_id' => ['default' => null, 'rules' => ['nullable', 'string', 'max:64', 'regex:/^(-?\d+|@\w+)$/']],
            // ce mai pleacă în canale, pe lângă update-uri: sarcinile închise sau blocate; în canalul echipei, dimineața și săptămâna
            'channel_tasks' => ['default' => true, 'rules' => ['boolean']],
            'channel_morning' => ['default' => true, 'rules' => ['boolean']],
            'channel_week' => ['default' => true, 'rules' => ['boolean']],
            // emailul (invitații, „Ai uitat parola?", rezumatul săptămânal); implicit, ce e în .env
            'mail_mailer' => ['default' => config('mail.default') === 'smtp' ? 'smtp' : 'off', 'rules' => ['required', Rule::in(['off', 'smtp'])]],
            'mail_host' => ['default' => config('mail.mailers.smtp.host'), 'rules' => ['nullable', 'required_if:data.mail_mailer,smtp', 'string', 'max:255']],
            'mail_port' => ['default' => (int) config('mail.mailers.smtp.port') ?: 587, 'rules' => ['nullable', 'integer', 'min:1', 'max:65535']],
            'mail_secure' => ['default' => config('mail.mailers.smtp.scheme') === 'smtps' ? 'ssl' : 'starttls', 'rules' => ['required', Rule::in(['starttls', 'ssl'])]],
            'mail_username' => ['default' => config('mail.mailers.smtp.username'), 'rules' => ['nullable', 'string', 'max:255']],
            'mail_password' => ['default' => config('mail.mailers.smtp.password'), 'rules' => ['nullable', 'string', 'max:255'], 'secret' => true],
            'mail_from_address' => ['default' => config('mail.from.address'), 'rules' => ['nullable', 'required_if:data.mail_mailer,smtp', 'email', 'max:255']],
            'mail_from_name' => ['default' => config('mail.from.name') ?: 'cemainou', 'rules' => ['nullable', 'string', 'max:100']],
            // emailul care devine sarcină: adresa cutiei (fiecare proiect primește adresa+cheie@domeniu); gol = oprit
            'inbound_address' => ['default' => null, 'rules' => ['nullable', 'email', 'max:255']],
        ];
    }

    public static function get(string $key): mixed
    {
        return app(self::class)->value($key);
    }

    /** @return array<string, mixed> toate setările, cu valorile de acum */
    public static function all(): array
    {
        return collect(self::definitions())->keys()->mapWithKeys(fn (string $key) => [$key => self::get($key)])->all();
    }

    /** Salvează valorile deja validate (doar cheile cunoscute); cele egale cu valoarea implicită se păstrează și ele, ca alegere explicită. */
    public static function save(array $values, User $by): void
    {
        $known = collect(array_intersect_key($values, self::definitions()))->map(fn ($value, $key) => self::cast($key, $value))
            ->map(fn ($value, $key) => ($value !== null && (self::definitions()[$key]['secret'] ?? false)) ? Crypt::encryptString($value) : $value)->all();
        DB::transaction(function () use ($known, $by) {
            foreach ($known as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by->id]);
            }
        });
        app(self::class)->stored = null;
    }

    /** Valoarea din formular (text) în tipul setării: număr, da / nu, text; gol = null (ex. memento oprit). */
    public static function cast(string $key, mixed $value): mixed
    {
        $rules = self::definitions()[$key]['rules'];

        return match (true) {
            $value === null || $value === '' => null,
            in_array('array', $rules, true) => array_values(array_map('intval', (array) $value)),
            in_array('integer', $rules, true) => (int) $value,
            in_array('boolean', $rules, true) => (bool) $value,
            default => (string) $value,
        };
    }

    public function value(string $key): mixed
    {
        $definition = self::definitions()[$key] ?? throw new InvalidArgumentException("Unknown setting {$key}.");
        $this->stored ??= $this->load();

        if (! array_key_exists($key, $this->stored)) {
            return $definition['default'];
        }
        if (($definition['secret'] ?? false) && $this->stored[$key] !== null) {
            try {
                return Crypt::decryptString($this->stored[$key]);
            } catch (DecryptException) {
                return null;                                                   // APP_KEY schimbată: secretul trebuie pus din nou
            }
        }

        return $this->stored[$key];
    }

    private function load(): array
    {
        try {
            return Setting::pluck('value', 'key')->all();
        } catch (QueryException) {
            return [];                                                         // tabelul încă nu există (instalare, înainte de migrare)
        }
    }
}
