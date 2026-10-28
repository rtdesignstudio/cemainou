<?php

namespace Database\Seeders;

use App\Enums\ActivityKind;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkDay;
use App\Models\WorkSession;
use App\Support\Demo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Date fictive pentru dezvoltare locală și pentru demo-ul public: o echipă, clienți, proiecte, update-uri pe ultimele zile și sesiunile de azi,
 * plus povestea de pe pagina publică (rebrandingul Vinăriei Exemplu: Dumitru designer, Mihai colaborator, Elena clientul) și cea din animația
 * „cum lucrează" (lansarea Cafenelei Boabe: Ana textele, Dumitru afișul, Maria postările, Ion reclamele, blocat până vin pozele).
 * Doar nume inventate (repo-ul poate deveni public). Rulează: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    /** Textul în limba demo-ului (cemainou.demo_locale: ro sau en), ca demo-ul în engleză să aibă și conținutul în engleză. */
    public static function t(string $ro, string $en): string
    {
        return config('cemainou.demo_locale') === 'en' ? $en : $ro;
    }

    /** Un cont inventat, fără factory (în arhiva de producție, deci și în demo-ul public, pachetele de dezvoltare lipsesc). */
    private static function person(string $name, string $role): array
    {
        return ['name' => $name, 'role' => $role, 'is_active' => true, 'email_verified_at' => now(),
            'password' => config('cemainou.demo') ? Str::random(40) : 'password'];   // demo-ul public intră dintr-un clic: parola nu folosește nimănui
    }

    /** O zi de lucru întreagă și credibilă pentru Ana, pe ore fixe de azi (doar cele trecute deja), ca graficul „Ziua mea" să arate ca la un om real. */
    private function anasDay(User $ana, Project $rebrand, Project $site, Project $labels, string $tz): void
    {
        $blocks = [['08:12', '09:05', $site], ['09:10', '11:48', $rebrand], ['11:55', '12:25', $labels], ['13:20', '15:40', $rebrand], ['15:50', '16:35', $site], ['16:45', '18:05', $rebrand]];
        $session = null;
        foreach ($blocks as $i => [$from, $to, $project]) {
            $start = Carbon::parse('today '.$from, $tz)->utc();
            $end = Carbon::parse('today '.$to, $tz)->utc()->min(now()->subMinutes(3));
            if ($start->gte($end)) {
                break;                                                     // restul zilei n-a venit încă
            }
            $session = WorkSession::create(['user_id' => $ana->id, 'project_id' => $project->id, 'agent_session_id' => 'demo-ana-'.$i, 'source' => 'claude-code', 'started_at' => $start, 'last_activity_at' => $end]);
            for ($t = $start->copy(), $n = 0; $t->lte($end); $n++, $t->addMinutes(3 + ($n * 5) % 7)) {
                Activity::create(['user_id' => $ana->id, 'project_id' => $project->id, 'work_session_id' => $session->id, 'kind' => $n ? ActivityKind::Prompt : ActivityKind::SessionStart, 'source' => 'claude-code', 'occurred_at' => $t->copy()]);
            }
        }
        $ana->update(['focus_text' => self::t('Brandbook-ul: pagina de culori', 'Brand book: the colour page'), 'focus_project_id' => $rebrand->id, 'focus_updated_at' => now(), 'last_seen_at' => now()]);
        $yesterday = now($tz)->subDay()->startOfDay();
        WorkDay::updateOrCreate(['user_id' => $ana->id, 'date' => $yesterday->toDateString()],
            ['started_at' => $yesterday->copy()->setTime(8, 40)->utc(), 'ended_at' => $yesterday->copy()->setTime(18, 15)->utc(), 'active_minutes' => 428, 'agent_minutes' => 301]);
        if (Carbon::parse('today 11:50', $tz)->utc()->lt(now())) {
            $update = Update::create(['user_id' => $ana->id, 'project_id' => $rebrand->id, 'summary' => self::t('Brandbook-ul a început: culorile și tipografia', 'Brand book started: colours and typography'), 'source' => 'claude-code',
                'started_at' => Carbon::parse('today 09:10', $tz)->utc(), 'period_to' => Carbon::parse('today 11:48', $tz)->utc(), 'minutes' => 158, 'commits' => 3, 'lines_added' => 412, 'lines_removed' => 128]);
            $update->forceFill(['created_at' => Carbon::parse('today 11:50', $tz)->utc()])->save();
            $update->addItems(['done' => [self::t('Paleta bordo și auriu, cu codurile pentru tipar', 'Burgundy and gold palette, with print codes'), self::t('Fontul titlurilor ales', 'Heading typeface chosen')], 'next' => [self::t('Pagina cu logo-ul pe fundaluri', 'The logo-on-backgrounds page')]]);
        }
    }

    public function run(): void
    {
        if (app()->isProduction() && ! config('cemainou.demo')) {   // conturile fictive au parola „password": niciodată pe un server real (doar în demo-ul public)
            $this->command?->error('Demo data is only for local development.');

            return;
        }

        $team = collect([
            ['Ana Popescu', 'ana@cemainou.test', 'lead'],
            ['Ion Rusu', 'ion@cemainou.test', 'member'],
            ['Maria Ceban', 'maria@cemainou.test', 'member'],
            ['Dumitru Lungu', 'dumitru@cemainou.test', 'member'],
            ['Mihai Rotaru', 'mihai@cemainou.test', 'collaborator'],
        ])->map(fn ($u) => User::firstOrCreate(['email' => $u[1]], self::person($u[0], $u[2])));
        [$ana, $ion, $maria, $dumitru, $mihai] = $team->all();
        if (Demo::withAdmin()) {                                           // doar local: paginile de admin, verificate pe aceleași date
            User::firstOrCreate(['email' => 'admin@cemainou.test'], self::person('Radu Admin', 'admin'));
        }

        $nord = Client::firstOrCreate(['slug' => 'studio-nord'], ['name' => 'Studio Nord', 'color' => '#2563eb', 'contact_name' => 'Elena Nord']);
        $vin = Client::firstOrCreate(['slug' => 'vinaria-exemplu'], ['name' => self::t('Vinăria Exemplu', 'Example Winery'), 'color' => '#9f1239']);
        $intern = Client::firstOrCreate(['slug' => 'intern'], ['name' => self::t('Intern', 'Internal'), 'color' => '#525252']);

        $site = Project::firstOrCreate(['slug' => 'site-studio-nord'], ['name' => 'Site Studio Nord', 'client_id' => $nord->id, 'lead_id' => $ana->id, 'categories' => ['development'], 'repo_url' => 'git@github.com:firma-demo/studio-nord.git']);
        $labels = Project::firstOrCreate(['slug' => 'etichete-vin'], ['name' => self::t('Etichete vin', 'Wine labels'), 'client_id' => $vin->id, 'lead_id' => $ana->id, 'categories' => ['design']]);
        $tool = Project::firstOrCreate(['slug' => 'cemainou'], ['name' => 'cemainou', 'client_id' => $intern->id, 'categories' => ['development'], 'repo_url' => 'https://github.com/firma-demo/cemainou']);

        // povestea de pe pagina publică: rebrandingul Vinăriei Exemplu (sarcinile, în DemoTasksSeeder)
        $rebrand = Project::firstOrCreate(['slug' => 'rebranding-vinaria-exemplu'], ['name' => self::t('Rebranding Vinăria Exemplu', 'Example Winery rebrand'), 'client_id' => $vin->id, 'lead_id' => $ana->id,
            'categories' => ['design'], 'client_visible' => true, 'description' => self::t('Logo nou, brandbook, designul și programarea site-ului.', 'New logo, brand book, website design and development.')]);
        $rebrand->members()->syncWithoutDetaching([$mihai->id => ['added_by' => $ana->id]]);

        // povestea din animația de pe pagina publică: lansarea Cafenelei Boabe (sarcinile, în DemoTasksSeeder)
        $boabe = Client::firstOrCreate(['slug' => 'cafeneaua-boabe'], ['name' => self::t('Cafeneaua Boabe', 'Boabe Café'), 'color' => '#8a5a2b']);
        $cafe = Project::firstOrCreate(['slug' => 'cafeneaua-boabe'], ['name' => self::t('Cafeneaua Boabe', 'Boabe Café'), 'client_id' => $boabe->id, 'lead_id' => $ana->id,
            'categories' => ['content', 'design'], 'client_visible' => true, 'description' => self::t('Lansarea cafenelei: afișul, postările din octombrie, reclamele pe Instagram.', 'The café launch: the poster, the October posts, Instagram ads.')]);

        // un om de la client: vede doar „Etichete vin", pornit ca vizibil clientului
        $labels->update(['client_visible' => true]);
        User::firstOrCreate(['email' => 'elena@vinaria.test'], self::person(self::t('Elena de la Vinăria', 'Elena from the Winery'), 'client') + ['client_id' => $vin->id]);

        // la o nouă rulare, datele fictive se refac de la zero (doar ale echipei fictive)
        Update::whereIn('user_id', $team->pluck('id'))->delete();
        WorkSession::whereIn('user_id', $team->pluck('id'))->delete();
        Activity::whereIn('user_id', $team->pluck('id'))->delete();
        $tz = 'Europe/Chisinau';

        $days = [
            [$ion, $site, self::t('Pagina de produs și filtrele din catalog', 'Product page and catalogue filters'), ['done' => [self::t('Filtre după marcă și model', 'Filters by brand and model'), self::t('Paginare fără reîncărcare', 'Pagination without reloading')], 'doing' => [self::t('Pagina de produs pe mobil', 'Product page on mobile')], 'next' => [self::t('Galeria foto cu glisare', 'Swipeable photo gallery')]]],
            [$maria, $labels, self::t('Trei direcții pentru eticheta de rosé', 'Three directions for the rosé label'), ['done' => [self::t('Moodboard aprobat', 'Moodboard approved'), self::t('Trei schițe de etichetă', 'Three label sketches')], 'decision' => [self::t('Hârtie texturată, fără folio', 'Textured paper, no foil')], 'next' => [self::t('Prezentare către client joi', 'Presentation to the client on Thursday')]]],
            [$ana, $site, self::t('Structura conținutului și textele de pe prima pagină', 'Content structure and home page copy'), ['done' => [self::t('Harta site-ului finalizată', 'Sitemap finished'), self::t('Texte pentru prima pagină', 'Home page copy')], 'blocked' => [self::t('Așteptăm fotografiile de la client', 'Waiting for the client’s photos')]]],
            [$ion, $tool, self::t('Modelul de date și ecranele de administrare', 'Data model and admin screens'), ['done' => [self::t('Tabelele pentru clienți, proiecte, update-uri', 'Tables for clients, projects, updates'), self::t('Roluri: admin, lider, membru', 'Roles: admin, lead, member')], 'doing' => [self::t('Uneltele MCP', 'The MCP tools')], 'decision' => [self::t('Banii rămân un modul opțional, doar pentru admin', 'Money stays an optional module, admins only')]]],
            [$maria, $labels, self::t('Varianta finală a etichetei, pregătită pentru tipar', 'Final label, ready for print'), ['done' => [self::t('Culori ajustate pentru tipar', 'Colours adjusted for print'), self::t('Fișiere livrate tipografiei', 'Files delivered to the printer')], 'next' => [self::t('Mockup pe sticlă pentru Instagram', 'Bottle mockup for Instagram')]]],
            [$ion, $site, self::t('Formularul de contact și trimiterea pe email', 'Contact form and email delivery'), ['done' => [self::t('Formular cu validare', 'Form with validation'), self::t('Trimitere prin SMTP', 'Sending over SMTP')], 'blocked' => [self::t('Lipsesc datele SMTP de la client', 'The client’s SMTP details are missing')], 'next' => [self::t('Teste pe telefon', 'Phone testing')]]],
            [$mihai, $rebrand, self::t('Structura site-ului nou, pregătită pentru programare', 'New site structure, ready for development'), ['done' => [self::t('Proiectul pornit, paginile de bază', 'Project set up, base pages')], 'next' => [self::t('Programarea începe după design', 'Development starts after the design')]]],
            [$dumitru, $rebrand, self::t('Logo-ul în bordo și auriu, aprobat de client', 'Logo in burgundy and gold, approved by the client'), ['done' => [self::t('Trei variante de logo', 'Three logo options'), self::t('Varianta aleasă de Elena, ajustată', 'Elena’s pick, refined')], 'doing' => [self::t('Brandbook-ul', 'The brand book')], 'decision' => [self::t('Bordo și auriu, ca pe eticheta veche', 'Burgundy and gold, like the old label')]]],
            [$dumitru, $cafe, self::t('Afișul de lansare, trimis la tipar', 'Launch poster, sent to print'), ['done' => [self::t('Două variante, aleasă a doua', 'Two versions, the second chosen'), self::t('Fișierele la tipografie', 'Files at the printer')], 'next' => [self::t('Varianta pentru Instagram', 'The Instagram version')]]],
            [$maria, $cafe, self::t('Primele 5 postări, programate', 'First 5 posts, scheduled'), ['done' => [self::t('Calendarul pe octombrie', 'The October calendar'), self::t('5 postări programate', '5 posts scheduled')], 'next' => [self::t('Celelalte 5, după poze', 'The other 5, after the photos')]]],
            [$ana, $cafe, self::t('Textele pentru cele 10 postări din octombrie', 'Copy for the 10 October posts'), ['done' => [self::t('10 texte, cu programul cafenelei', '10 texts, with the café’s opening hours'), self::t('Tonul: cald și scurt', 'Tone: warm and short')], 'next' => [self::t('Postările din noiembrie', 'The November posts')]]],
            [$ion, $cafe, self::t('Bugetul pentru reclame, pe 2 săptămâni', 'Ad budget, for 2 weeks'), ['done' => [self::t('Buget: 400 €, 2 săptămâni', 'Budget: €400, 2 weeks'), self::t('3 variante de reclamă', '3 ad versions')], 'blocked' => [self::t('Așteptăm pozele de la cafenea', 'Waiting for the café’s photos')]]],
        ];
        $ago = [50, 44, 27, 22, 3.5, 1.2, 0.9, 0.4, 6.2, 4.8, 2.3, 1.6];                          // cu câte ore în urmă, de la cel mai vechi la cel mai nou
        foreach ($days as $i => [$user, $project, $summary, $items]) {
            $at = Carbon::now()->subMinutes((int) ($ago[$i] * 60));
            $update = Update::create(['user_id' => $user->id, 'project_id' => $project->id, 'summary' => $summary, 'source' => 'claude-code',
                'started_at' => $at->copy()->setTimezone($tz)->setTime(9, 5 + $i)->utc(), 'period_to' => $at, 'minutes' => 120 + 25 * $i]);
            $update->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
            $update->addItems($items);
        }

        // azi: cine a început lucrul și la ce lucrează acum
        foreach ([[$ion, $site, '08:52', self::t('Galeria foto cu glisare', 'Swipeable photo gallery')], [$maria, $labels, '09:20', self::t('Mockup pe sticlă', 'Bottle mockup')]] as [$user, $project, $start, $focus]) {
            $started = Carbon::parse('today '.$start, $tz)->utc();
            if ($started->gt(now())) {
                $started = now()->subHours(2);                         // dacă rulezi devreme dimineața
            }
            $session = WorkSession::create(['user_id' => $user->id, 'project_id' => $project->id, 'agent_session_id' => 'demo-'.$user->id, 'source' => 'claude-code', 'started_at' => $started, 'last_activity_at' => now()]);
            // semnale la 4-12 minute, cu o pauză de prânz de ~50 de minute la jumătatea zilei
            for ($t = $started->copy(), $n = 0; $t->lt(now()->subMinutes(2)); $n++) {
                Activity::create(['user_id' => $user->id, 'project_id' => $project->id, 'work_session_id' => $session->id, 'kind' => $n ? ActivityKind::Prompt : ActivityKind::SessionStart, 'source' => 'claude-code', 'occurred_at' => $t->copy()]);
                $t->addMinutes($n % 9 === 8 && $t->copy()->setTimezone($tz)->hour >= 12 && $t->copy()->setTimezone($tz)->hour < 14 ? 50 : 4 + ($n * 7) % 9);
            }
            $user->update(['focus_text' => $focus, 'focus_project_id' => $project->id, 'focus_updated_at' => now(), 'last_seen_at' => now()]);
        }

        // ziua Anei (cardul „Ziua mea" și capturile de pe pagina publică): bucăți de lucru pe trei proiecte, cu pauză de prânz, oprite la ora de acum;
        // ieri, o zi plină; azi, un update cu commit-uri și o sarcină încheiată
        $this->anasDay($ana, $rebrand, $site, $labels, $tz);

        // fără „Primii pași" și fără propunerea turului peste paginile demo (în demo, limba o alege fiecare la intrare: SetLocale)
        User::whereIn('id', $team->pluck('id'))->orWhere('email', 'elena@vinaria.test')->update(['getting_started_hidden_at' => now(), 'tour_seen_at' => now()]);

        $this->call(DemoTasksSeeder::class);
    }
}
