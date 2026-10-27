<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\ProjectRepo;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Sarcini fictive pentru ecrane, pe proiectele demo (doar nume inventate). La re-rulare le șterge pe cele vechi ale echipei demo. */
class DemoTasksSeeder extends Seeder
{
    public function run(): void
    {
        $team = User::whereIn('email', ['ana@cemainou.test', 'ion@cemainou.test', 'maria@cemainou.test', 'dumitru@cemainou.test', 'mihai@cemainou.test'])->get()->keyBy('email');
        [$ana, $ion, $maria] = [$team['ana@cemainou.test'], $team['ion@cemainou.test'], $team['maria@cemainou.test']];
        [$dumitru, $mihai] = [$team['dumitru@cemainou.test'] ?? null, $team['mihai@cemainou.test'] ?? null];
        Task::whereIn('created_by', $team->pluck('id'))->delete();
        $site = Project::where('slug', 'site-studio-nord')->firstOrFail();
        $labels = Project::where('slug', 'etichete-vin')->firstOrFail();

        Task::add($site, $ana, DemoSeeder::t('Galeria foto cu glisare', 'Swipeable photo gallery'), DemoSeeder::t('Glisare pe mobil, săgeți pe desktop, imagini încărcate la nevoie.', 'Swipe on mobile, arrows on desktop, images loaded on demand.'), $ion)->take($ion);
        Task::add($site, $ana, DemoSeeder::t('Textele pentru paginile de servicii', 'Copy for the service pages'), assignee: $ana)->moveTo(TaskStatus::Doing, $ana);
        Task::add($site, $ion, DemoSeeder::t('Trimiterea emailurilor din formular', 'Sending the form emails'), assignee: $ion)->moveTo(TaskStatus::Blocked, $ion, DemoSeeder::t('Lipsesc datele SMTP de la client', 'The client’s SMTP details are missing'));
        Task::add($site, $ana, DemoSeeder::t('Formularul de abonare', 'Newsletter signup form'));
        Task::add($site, $ana, DemoSeeder::t('Pagina de contact pe mobil', 'Contact page on mobile'), assignee: $maria);
        Task::add($site, $ion, DemoSeeder::t('Optimizarea imaginilor', 'Image optimisation'));
        Task::add($site, $ana, DemoSeeder::t('Structura conținutului', 'Content structure'), assignee: $ana)->moveTo(TaskStatus::Done, $ana);
        Task::add($labels, $ana, DemoSeeder::t('Fișierele de tipar pentru eticheta de rosé', 'Print files for the rosé label'), assignee: $maria);

        // povestea de pe pagina publică: rebrandingul Vinăriei Exemplu, cu un colaborator (Mihai) și clientul care deblochează o sarcină
        if ($dumitru && $mihai) {
            $rebrand = Project::where('slug', 'rebranding-vinaria-exemplu')->firstOrFail();
            $elena = User::where('email', 'elena@vinaria.test')->first();
            Task::add($rebrand, $ana, DemoSeeder::t('Logo, 3 variante', 'Logo, 3 options'), DemoSeeder::t('Culorile clientului: bordo și auriu, ca pe eticheta veche.', 'The client’s colours: burgundy and gold, like the old label.'), $dumitru)->moveTo(TaskStatus::Done, $dumitru);
            Task::add($rebrand, $ana, DemoSeeder::t('Brandbook', 'Brand book'), assignee: $dumitru)->moveTo(TaskStatus::Doing, $dumitru);
            Task::add($rebrand, $ana, DemoSeeder::t('Design site', 'Website design'), assignee: $dumitru);
            Task::add($rebrand, $ana, DemoSeeder::t('Programare site', 'Website development'), DemoSeeder::t('După design. Mihai vede doar acest proiect.', 'After the design. Mihai sees only this project.'), $mihai);
            if ($elena) {
                Task::where('created_by', $elena->id)->delete();                      // la o nouă rulare, fără dubluri
                Task::add($rebrand, $elena, DemoSeeder::t('Fotografiile noi ale cramei', 'New photos of the winery'), DemoSeeder::t('Le trimitem până vineri.', 'We will send them by Friday.'), $ana);
            }
        }

        // povestea din animația de pe pagina publică: lansarea Cafenelei Boabe
        if ($dumitru && ($cafe = Project::where('slug', 'cafeneaua-boabe')->first())) {
            Task::add($cafe, $ana, DemoSeeder::t('Afișul de lansare', 'Launch poster'), DemoSeeder::t('A3, pentru vitrină și pentru Instagram.', 'A3, for the window and for Instagram.'), $dumitru)->moveTo(TaskStatus::Done, $dumitru);
            Task::add($cafe, $ana, DemoSeeder::t('Postările lunii', 'October posts'), DemoSeeder::t('10 postări: textele, calendarul, programarea. Cu programul cafenelei în fiecare.', '10 posts: copy, calendar, scheduling. With the café’s opening hours in each.'), $ana)->moveTo(TaskStatus::Doing, $ana);
            Task::add($cafe, $ana, DemoSeeder::t('Reclame Instagram', 'Instagram ads'), DemoSeeder::t('Buget 400 €, 2 săptămâni, 3 variante.', 'Budget €400, 2 weeks, 3 versions.'), $ion)->moveTo(TaskStatus::Blocked, $ion, DemoSeeder::t('Așteptăm pozele de la cafenea', 'Waiting for the café’s photos'));
            Task::add($cafe, $ana, DemoSeeder::t('Meniul de toamnă pe site', 'Autumn menu on the website'), assignee: $maria);
        }

        // cunoștințele proiectului: note de la echipă și un repo propus de Ion, de legat de liderul Ana
        ProjectNote::whereIn('user_id', $team->pluck('id'))->delete();
        $site->notes()->create(['user_id' => $ion->id, 'body' => DemoSeeder::t('Pe staging se intră doar prin VPN-ul biroului.', 'Staging is reachable only through the office VPN.'), 'source' => 'claude-code']);
        $site->notes()->create(['user_id' => $ana->id, 'body' => DemoSeeder::t('Clientul vrea toate textele în română și în rusă, aprobate de Elena înainte de publicare.', 'The client wants all copy in Romanian and Russian, approved by Elena before publishing.')]);
        ProjectRepo::where('suggested_by', $ion->id)->delete();
        $site->repos()->create(['url' => 'git@github.com:ion-rusu/studio-nord-galerie.git', 'status' => 'pending', 'suggested_by' => $ion->id]);
    }
}
