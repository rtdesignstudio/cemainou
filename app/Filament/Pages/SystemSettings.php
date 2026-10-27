<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\SetLocale;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\AgentCommands;
use App\Support\Backups;
use App\Support\Branding;
use App\Support\Channels;
use App\Support\FeedbackUpstream;
use App\Support\Files;
use App\Support\InboundMail;
use App\Support\KeepInTouch;
use App\Support\License;
use App\Support\Releases;
use App\Support\Settings;
use App\Support\TeamMail;
use App\Support\Telegram;
use App\Support\Timezones;
use App\Support\Updater;
use App\Support\WeeklyDigest;
use BackedEnum;
use Composer\InstalledVersions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Compartimentul adminului: setările întregului sistem (valorile implicite pentru oamenii noi, timpul, fișierele, agenții, datele și securitatea)
 * și, alături, starea sistemului. Valorile și regulile stau în App\Support\Settings; ce nu s-a schimbat aici vine din config / .env.
 */
class SystemSettings extends Page
{
    protected string $view = 'filament.pages.system-settings';

    protected static ?string $slug = 'settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 9;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Settings');
    }

    public function getSubheading(): ?string
    {
        return __('How the whole team works. Only admins see this page.');
    }

    public function mount(): void
    {
        $this->form->fill($this->current());
    }

    /** Valorile de acum; secretele (parola SMTP) nu se trimit niciodată în pagină: câmpul gol = rămâne cea salvată. */
    private function current(): array
    {
        return collect(Settings::all())->map(fn ($value, $key) => (Settings::definitions()[$key]['secret'] ?? false) ? '' : $value)->all();
    }

    public function form(Schema $schema): Schema
    {
        $days = __('days');

        return $schema->statePath('data')->components([
            Section::make(__('Company'))->aside()->description(__('Your company\'s name and logo, on Today instead of the New task button. SVG or PNG, at most 1 MB; a dark logo on a transparent background looks best.'))->schema([
                TextInput::make('company_name')->label(__('Name'))->maxLength(100)->placeholder('RT Design Studio'),
                View::make('filament.pages.partials.logo-preview')->viewData(fn () => ['logo' => Branding::logoUrl(), 'name' => Branding::name()]),   // logo-ul, cum apare pe Azi
                FileUpload::make('company_logo')->label(fn () => Branding::logoUrl() ? __('Replace the logo') : __('Logo'))->disk('data')->directory('branding')->visibility('private')
                    ->acceptedFileTypes(array_values(Branding::TYPES))->maxSize(1024)->maxFiles(1)->previewable(false)->extraAttributes(['class' => 'wsa-logo-upload'])
                    ->getUploadedFileUsing(fn (string $file) => ['name' => basename($file), 'size' => Storage::disk('data')->size($file), 'type' => Storage::disk('data')->mimeType($file), 'url' => Branding::logoUrl()])
                    ->deleteUploadedFileUsing(fn () => null),                 // fișierul vechi se șterge abia la salvare
            ]),
            Section::make(__('Team'))->aside()->description(__('Defaults for new people and for the sign-in page. Everyone can change theirs in their profile.'))->schema([
                Select::make('locale')->label(__('Language'))->options(SetLocale::LOCALES)->required()->native(false)->selectablePlaceholder(false),
                Select::make('timezone')->label(__('Timezone'))->options(fn () => Timezones::options())->searchable()->required()->selectablePlaceholder(false),
            ]),
            Section::make(__('Time'))->aside()->description(__('How work time is measured from the agents\' sessions and when people are reminded to report.'))->schema([
                TextInput::make('gap_minutes')->label(__('A pause counts as work up to'))->integer()->suffix(__('minutes'))->required()
                    ->helperText(__('Reading an answer, testing, writing the next prompt. A longer pause is not counted. It is also how long someone shows as active now.')),
                TextInput::make('workday_hours')->label(__('A work day has'))->integer()->suffix(__('hours'))->required()
                    ->helperText(__('Totals of a work day or more show as days: 1 day 2h 15m. Anyone can turn it off in their profile.')),
                TextInput::make('report_days')->label(__('Reports and added time go back at most'))->integer()->suffix($days)->required()
                    ->helperText(__('How far back an agent may place work split by day, or add a meeting.')),
                TextInput::make('history_days')->label(__('/history rebuilds from git at most'))->integer()->suffix($days)->required()
                    ->helperText(__('How far back an agent may fill in a project\'s past work, one update per day.')),
            ]),
            Section::make(__('Files'))->aside()->description(__('Files attached to projects, kept in data/files and served only to people signed in.'))->schema([
                TextInput::make('max_upload_mb')->label(__('Largest file'))->integer()->suffix('MB')->required()
                    ->helperText(fn () => __('The server\'s PHP accepts at most :size MB per file; a larger value needs its limit raised too.', ['size' => Files::phpLimitMb()])),
                Toggle::make('allow_html')->label(__('HTML presentations and SVG images'))
                    ->helperText(__('They open isolated from the app: their scripts run, but cannot reach the session or the team\'s data. Off: new ones are refused.')),
            ]),
            Section::make(__('Agents'))->aside()->description(__('Limits for the agents connected over MCP and for their keys.'))->schema([
                TextInput::make('mcp_per_minute')->label(__('Requests per minute, per person'))->integer()->required()
                    ->helperText(__('All of a person\'s agents together. It stops an agent that runs wild, not normal work.')),
                TextInput::make('mcp_ip_per_minute')->label(__('Requests per minute, per address, before sign-in'))->integer()->required()
                    ->helperText(__('Noise without a key, a broken agent.')),
                TextInput::make('token_idle_days')->label(__('Unused agent keys are revoked after'))->integer()->suffix($days)->required()
                    ->helperText(__('Old laptops, forgotten devices. Keys in use do not expire.')),
                Toggle::make('clients_use_agents')->label(__('Clients can connect their agents'))
                    ->helperText(__('From their profile, with their account (the Claude app, ChatGPT). They see only their visible projects: the tasks, the documents and files shared with them, their notifications; they can add a task (it goes to the project lead) and comment. No time is measured for them.')),
            ]),
            Section::make(__('Email'))->aside()->description(__('For invitations, password resets and the weekly summary. Without it, invitations are links you send yourself.'))->schema([
                Select::make('mail_mailer')->label(__('Sending'))->native(false)->selectablePlaceholder(false)->live()
                    ->options(['off' => __('Off'), 'smtp' => __('Through an SMTP server')]),
                TextInput::make('mail_host')->label(__('SMTP server'))->placeholder('smtp.example.com')->visible(fn ($get) => $get('mail_mailer') === 'smtp'),
                TextInput::make('mail_port')->label(__('Port'))->integer()->visible(fn ($get) => $get('mail_mailer') === 'smtp'),
                Select::make('mail_secure')->label(__('Security'))->native(false)->selectablePlaceholder(false)->visible(fn ($get) => $get('mail_mailer') === 'smtp')
                    ->options(['starttls' => __('STARTTLS (usually port 587)'), 'ssl' => __('SSL (usually port 465)')]),
                TextInput::make('mail_username')->label(__('User'))->autocomplete('off')->visible(fn ($get) => $get('mail_mailer') === 'smtp'),
                TextInput::make('mail_password')->label(__('Password'))->password()->revealable(false)->autocomplete('new-password')
                    ->visible(fn ($get) => $get('mail_mailer') === 'smtp')
                    ->placeholder(fn () => filled(Settings::get('mail_password')) ? __('saved; type only to replace it') : null)
                    ->helperText(__('Kept encrypted; it is never shown again.')),
                TextInput::make('mail_from_address')->label(__('Sender address'))->email()->placeholder('cemainou@example.com')->visible(fn ($get) => $get('mail_mailer') === 'smtp'),
                TextInput::make('mail_from_name')->label(__('Sender name'))->visible(fn ($get) => $get('mail_mailer') === 'smtp'),
                Actions::make([
                    Action::make('testMail')->label(__('Send me a test email'))->outlined()
                        ->visible(fn ($get) => $get('mail_mailer') === 'smtp')
                        // cu ce e scris acum în formular, chiar nesalvat: încerci variante, salvezi doar pe cea care merge
                        ->action(function () {
                            $values = collect($this->data)->only(['mail_mailer', 'mail_host', 'mail_port', 'mail_secure', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name'])->all();
                            $error = TeamMail::test(auth()->user(), $values);
                            $unsaved = collect($values)->except('mail_password')->contains(fn ($value, $key) => (string) $value !== (string) Settings::get($key)) || filled($values['mail_password'] ?? null);
                            $error === null
                                ? Notification::make()->title(__('Test email sent to :email.', ['email' => auth()->user()->email]))
                                    ->body($unsaved ? __('These settings are not saved yet: press Save at the bottom of the page to keep them.') : null)->success()->send()
                                : Notification::make()->title(__('The test email did not leave.'))->body($error)->danger()->persistent()->send();
                        }),
                ])->key('mailActions'),
            ]),
            Section::make(__('Email to task'))->aside()->description(__('Every project gets its own address: an email sent or forwarded there becomes a task on the project, with its files. Only from people with an account who see the project; a client\'s email goes to the project lead.'))->schema([
                TextInput::make('inbound_address')->label(__('Mailbox address'))->email()->placeholder('tasks@example.com')->live(onBlur: true)
                    ->helperText(__('A mailbox on your email server. Each project uses it with its own key after +, so the server must accept addresses with + (most do). Empty = off.')),
                View::make('filament.pages.partials.inbound-mail')->visible(fn ($get) => filled($get('inbound_address')))
                    ->viewData(fn () => ['command' => preg_replace('/(lsphp|php-fpm|php-cgi)$/', 'php', PHP_BINARY).' '.base_path('artisan').' cemainou:inbound-mail', 'webhook' => route('inbound.mail', ['token' => InboundMail::token()]), 'recent' => InboundMail::recent()]),
            ]),
            Section::make(__('Channels'))->aside()->description(__('Every published update (not the internal ones) also goes to the team\'s channel. A project can have its own, in its settings. With the Telegram bot, people can also connect their account from their profile: their notifications come in a private chat, and a reply to a message about a task or an update becomes a comment.'))->schema([
                TextInput::make('slack_webhook')->label(__('Slack webhook'))->password()->revealable(false)->autocomplete('off')->placeholder(fn () => filled(Settings::get('slack_webhook')) ? __('saved; type only to replace it') : 'https://hooks.slack.com/services/...')
                    ->helperText(__('In Slack: Apps → Incoming Webhooks → the channel. Kept encrypted.')),
                TextInput::make('telegram_bot_token')->label(__('Telegram bot'))->password()->revealable(false)->autocomplete('off')->placeholder(fn () => filled(Settings::get('telegram_bot_token')) ? __('saved; type only to replace it') : '123456:ABC...')
                    ->helperText(__('The token from @BotFather. Kept encrypted.')),
                TextInput::make('telegram_chat_id')->label(__('Telegram chat'))->placeholder('-1001234567890')
                    ->helperText(__('The group\'s id (add the bot to the group first) or @channel.')),
                Toggle::make('channel_tasks')->label(__('Also tasks closed or blocked'))
                    ->helperText(__('Outside an update (an update already says what it moved), in the project\'s channel or the team\'s.')),
                Toggle::make('channel_morning')->label(__('Morning summary for the team'))
                    ->helperText(__('Weekdays after 9, in the team\'s channel: yesterday\'s updates and finished tasks, what is blocked, late and quiet.')),
                Toggle::make('channel_week')->label(__('The team\'s week, on Fridays'))
                    ->helperText(__('After 15, in the team\'s channel: tasks finished, hours, updates, people and the projects with the most time.')),
                Actions::make([
                    Action::make('testChannels')->label(__('Send a test message'))->outlined()
                        ->action(function () {
                            $error = Channels::test();
                            $error === null
                                ? Notification::make()->title(__('Test message sent.'))->success()->send()
                                : Notification::make()->title(__('The test message did not leave.'))->body($error)->danger()->persistent()->send();
                        }),
                ])->key('channelActions'),
            ]),
            Section::make(__('Badges'))->aside()->description(__('Small rewards for good habits: reporting regularly, unblocking colleagues, noting decisions, closing tasks with a proof, taking breaks. Never for long hours, no ranking between people. Each person sees their own and can hide them or show them to the team, from their profile.'))->schema([
                Toggle::make('badges_on')->label(__('Badges for the team')),
                Toggle::make('badges_animation')->label(__('A full-screen animation when someone gets a new one')),
                Toggle::make('jokes_on')->label(__('A joke a day on Today'))->helperText(__('About free time and life at home, the same for the whole team. Everyone can hide it for themselves.')),
            ]),
            // Muzica biroului: tonomatul pe YouTube; pagina biroului cântă coada pusă de echipă din bara de sus
            Section::make(__('Office music'))->id('music')->aside()->description(__('A jukebox on YouTube: the office computer keeps the office page open and plays the queue; anyone on the team adds a YouTube link from the music icon at the top, pauses or skips. Up to 3 songs each; when the queue ends, it plays from the team history. Clients do not see it.'))->schema([
                Toggle::make('music_on')->label(__('Office music for the team'))->live(),
                Select::make('music_players')->label(__('Who can play the music on their computer'))->multiple()->native(false)
                    ->options(fn () => User::where('role', '!=', 'client')->where('is_active', true)->where('role', '!=', 'admin')->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText(__('Admins always can. Everyone else on the team adds songs, pauses and skips, but the sound stays where it is.'))
                    ->visible(fn (Get $get) => (bool) $get('music_on')),
                Text::make(fn () => new HtmlString('<a href="'.e(route('music.office')).'" target="_blank" class="wsa-link">'.e(__('Open the office page')).'</a> · '.e(__('on the office computer, then press Start.'))))->visible(fn () => (bool) Settings::get('music_on')),
            ]),
            Section::make(__('Weekly summary'))->aside()->description(__('By email, to everyone who has not turned it off: the week on the projects they see. Clients get only theirs, without internal updates.'))->schema([
                Select::make('digest_day')->label(__('Day'))->placeholder(__('Off'))->native(false)
                    ->options(collect(range(1, 7))->mapWithKeys(fn (int $day) => [$day => ucfirst(now()->startOfWeek()->addDays($day - 1)->locale(app()->getLocale())->dayName)])->all()),
                Select::make('digest_hour')->label(__('Hour'))->native(false)->selectablePlaceholder(false)
                    ->options(collect(range(0, 23))->mapWithKeys(fn (int $hour) => [$hour => sprintf('%02d:00', $hour)])->all())
                    ->helperText(__('In the team\'s timezone. It leaves with the first visit after this hour (or on the hour, with cron).')),
                Actions::make([
                    Action::make('previewDigest')->label(__('Send me this week\'s summary now'))->outlined()
                        ->action(function () {
                            if (! TeamMail::ready()) {
                                Notification::make()->title(__('Email is not set up yet.'))->warning()->send();

                                return;
                            }
                            app(WeeklyDigest::class)->send(now()->subWeek(), now(), only: auth()->user());
                            Notification::make()->title(__('Summary sent to :email.', ['email' => auth()->user()->email]))->success()->send();
                        }),
                ])->key('digestActions'),
            ]),
            Section::make(__('Backup'))->aside()->description(__('The database and the files, in data/backups. APP_KEY (from .env) is not in the archive: keep it separately, the encrypted settings need it.'))->schema([
                Toggle::make('backup_daily')->label(__('Every day, with the daily cleanup')),
                TextInput::make('backup_keep')->label(__('Keep the last'))->integer()->suffix(__('backups'))->required(),
                Actions::make([
                    Action::make('backupNow')->label(__('Make a backup now'))->outlined()
                        ->action(function () {
                            abort_unless(static::canAccess(), 403);
                            $path = Backups::make();
                            Notification::make()->title(__('Backup made: :name.', ['name' => basename($path)]))->success()->send();
                        }),
                ])->key('backupActions'),
                View::make('filament.pages.partials.backups')->viewData(fn () => ['backups' => Backups::all()]),
            ]),
            Section::make(__('New versions'))->aside()->description(__('New versions are announced on rt.md. You download the archive and upload it on the update page: it is installed only with the RT signature, after a backup.'))->schema([
                Toggle::make('update_check')->label(__('Check rt.md for new versions, once a day'))
                    ->helperText(__('Only the list of versions is read; nothing about the team is sent.')),
                Toggle::make('feedback_to_rt')->label(__('Also send the team\'s feedback about cemainou to RT Design Studio'))
                    ->helperText(__('What goes: the text, the page (without your address), the screen, the browser, the version and the files. Never the person\'s name or email. It helps us fix what bothers you.'))
                    ->hidden(fn () => FeedbackUpstream::inbox()),
                // „Ține-mă la curent": opțional; doar așa află RT de instalare (emailul, firma, versiunea), ca să vă scrie despre versiuni și să vă ajute mai repede
                Toggle::make('keep_in_touch')->label(__('Keep me posted'))->live()
                    ->helperText(__('Optional. RT Design Studio gets this installation\'s number, the email and the company below, and the version, to tell you about new versions and help you faster. Nothing else is ever sent; turn it off any time.'))
                    ->hidden(fn () => FeedbackUpstream::inbox()),
                TextInput::make('keep_in_touch_email')->label(__('Email'))->email()->maxLength(255)
                    ->visible(fn ($get) => $get('keep_in_touch') && ! FeedbackUpstream::inbox())->required(fn ($get) => (bool) $get('keep_in_touch')),
                TextInput::make('keep_in_touch_company')->label(__('Company (optional)'))->maxLength(255)
                    ->visible(fn ($get) => $get('keep_in_touch') && ! FeedbackUpstream::inbox()),
            ]),
            Section::make(__('Support cemainou'))->id('license')->aside()->description(__('cemainou is free for any team, with any number of people, and nothing in it ever waits for a payment. If it helps you, support it: a yearly contribution you choose brings a supporter key, priority support by email and a vote on what comes next.'))->schema([
                Text::make(fn () => self::licenseLine()),
                TextInput::make('license_key')->label(__('Supporter key'))->maxLength(2000)->placeholder('CMN1.…')
                    ->helperText(new HtmlString(e(__('It comes by email when you support cemainou.')).' <a href="https://rt.md/cemainou/license/#support" target="_blank" class="wsa-link">'.e(__('Support cemainou')).'</a> · '
                        .'<a href="https://rt.md/cemainou/license/" target="_blank" class="wsa-link">'.e(__('Read the license')).'</a>')),
            ]),
            Section::make(__('Privacy'))->aside()->description(__('The Privacy page: what cemainou keeps, for how long and who sees it, filled in from these settings. Linked from the sign-in page, the invitation and the account menu.'))->schema([
                TextInput::make('legal_name')->label(__('Legal name'))->maxLength(150)->placeholder('RT Design Studio SRL')->helperText(__('Who is responsible for the data. Empty: the company name.')),
                TextInput::make('legal_address')->label(__('Address'))->maxLength(255),
                TextInput::make('privacy_email')->label(__('Email for data requests'))->email()->maxLength(255)->helperText(__('Empty: the address emails are sent from.')),
                Toggle::make('privacy_public')->label(__('Public page'))
                    ->helperText(__('Off: only for people signed in; invitations still link to it, with a link valid while the invitation is.')),
                Text::make(fn () => new HtmlString('<a href="'.e(route('privacy')).'" target="_blank" class="wsa-link">'.e(__('Open the page')).'</a>')),
            ]),
            Section::make(__('Data and security'))->aside()->description(__('What is kept, and for how long; how strong passwords must be.'))->schema([
                TextInput::make('keep_raw_days')->label(__('Raw activity signals are kept for'))->integer()->suffix($days)->required()
                    ->helperText(__('Work days stay as a summary; only the raw signals and the log of agent calls are deleted.')),
                TextInput::make('password_min')->label(__('Shortest password'))->integer()->suffix(__('characters'))->required()
                    ->helperText(__('For new passwords; the ones already set keep working.')),
                Toggle::make('two_factor_enabled')->label(__('Two-step sign-in'))->live()
                    ->helperText(__('With an authenticator app (Google Authenticator, 1Password...) and recovery codes. Everyone turns it on in their profile.')),
                Toggle::make('two_factor_admins')->label(__('Required for admins'))->visible(fn ($get) => (bool) $get('two_factor_enabled'))
                    ->helperText(__('Admins without it are sent to set it up at their next visit.')),
            ]),
        ]);
    }

    /** Susținerea, într-un rând: gratuit (câți oameni), susținător (cine, până când), încheiată sau cheia altei instalări. */
    private static function licenseLine(): string
    {
        $status = License::status();

        $line = match ($status['state']) {
            'supporter' => __('Supporter: :licensee, until :date. Thank you.', ['licensee' => $status['licensee'], 'date' => $status['until']->translatedFormat('j F Y')]),
            'expired' => __('Your support ended on :date. Thank you for it; renew it whenever you want.', ['date' => $status['until']->translatedFormat('j F Y')]),
            'free' => __('Free, with :people people.', ['people' => $status['people']]),
            default => (string) License::problem(),
        };

        return $line.' '.__('Installation number: :id (send it with your support).', ['id' => License::installation()]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $rules = collect(Settings::definitions())->mapWithKeys(fn (array $definition, string $key) => ["data.{$key}" => $definition['rules']])->all();
        foreach ((array) ($this->data['company_logo'] ?? []) as $file) {                  // doar SVG sau PNG, cel mult 1 MB, verificat înainte să ajungă pe disc
            if ($file instanceof TemporaryUploadedFile && (! isset(Branding::TYPES[strtolower($file->getClientOriginalExtension())]) || $file->getSize() > 1024 * 1024)) {
                $this->addError('data.company_logo', __('The logo must be an SVG or PNG file of at most 1 MB.'));

                return;
            }
        }
        $previousLogo = Settings::get('company_logo');
        foreach ($this->form->getFlatComponents() as $component) {                     // logo-ul nou: din încărcarea temporară pe discul data
            if ($component instanceof FileUpload) {
                $component->saveUploadedFiles();
            }
        }
        $this->data['company_logo'] = collect((array) ($this->data['company_logo'] ?? []))->first() ?: null;
        $this->validate($rules);

        $values = collect($this->data)->reject(fn ($value, $key) => (Settings::definitions()[$key]['secret'] ?? false) && blank($value))->all();   // gol = rămâne cea salvată
        $contact = KeepInTouch::state();
        Settings::save($values, auth()->user());
        KeepInTouch::sync($contact);                                                   // „Ține-mă la curent": RT află doar dacă adminul l-a pornit sau schimbat
        $telegram = Telegram::connectWebhook();                                        // botul trimite răspunsurile și legările aici; null = a mers sau nu e bot
        if ($previousLogo && $previousLogo !== Settings::get('company_logo')) {
            Storage::disk('data')->delete($previousLogo);
        }
        $this->form->fill($this->current());

        $notification = Notification::make()->title(__('Settings saved.'))->success();
        if (Settings::get('max_upload_mb') > Files::phpLimitMb()) {
            $notification->body(__('PHP on this server still accepts at most :size MB per file: raise upload_max_filesize and post_max_size too.', ['size' => Files::phpLimitMb()]))->warning();
        }
        if ($telegram !== null) {
            $notification->body(__('The Telegram bot could not be connected for replies (:error). Messages still go out; replies and account links need a public https address.', ['error' => Str::limit($telegram, 120)]))->warning();
        }
        $notification->send();
    }

    protected function getViewData(): array
    {
        $database = config('database.connections.'.config('database.default'));
        $databaseSize = ($database['driver'] ?? null) === 'sqlite' && is_file($database['database'] ?? '') ? filesize($database['database']) : null;
        $version = fn (string $package) => InstalledVersions::isInstalled($package) ? ltrim((string) InstalledVersions::getPrettyVersion($package), 'v') : null;
        $last = Setting::with('editor')->where('key', '!=', Updater::VERSION_KEY)->latest('updated_at')->first();   // versiunea aplicată nu e o alegere a cuiva

        return [
            'system' => array_filter([
                __('Version') => 'cemainou '.config('cemainou.version').(($release = Releases::available()) ? ' · '.__(':version available', ['version' => $release['version']]) : ''),
                __('Address') => config('app.url'),
                __('Agents (MCP)') => AgentCommands::mcpUrl(),
                __('Running on') => 'PHP '.PHP_VERSION.' · Laravel '.app()->version().' · Filament '.$version('filament/filament'),
                __('Database') => (['sqlite' => 'SQLite', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL'][$database['driver'] ?? ''] ?? ($database['driver'] ?? '')).($databaseSize !== null ? ' · '.Files::size($databaseSize) : ''),
                __('Files') => trans_choice(':count file|:count files', $count = Attachment::whereNotNull('path')->count(), ['count' => $count]).' · '.Files::size((int) Attachment::sum('size')),
                __('PHP limits') => __('files up to :size MB · memory :memory', ['size' => Files::phpLimitMb(), 'memory' => ini_get('memory_limit')]),
                __('Team') => __(':people active people · :projects active projects · :keys agent keys', [
                    'people' => User::team()->count(), 'projects' => Project::active()->count(), 'keys' => PersonalAccessToken::count(),
                ]),
                __('Daily cleanup') => Cache::get('cemainou.housekeeping.done') ?? __('not yet'),
                __('Last change') => $last ? ($last->editor?->name ?? __('Someone')).' · '.$last->updated_at->diffForHumans() : null,
            ]),
            'peopleUrl' => UserResource::getUrl(),
        ];
    }
}
