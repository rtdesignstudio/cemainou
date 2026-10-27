<?php

namespace App\Http\Controllers;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\WidgetDevice;
use App\Models\WidgetInvite;
use App\Support\FeedbackWidget;
use App\Support\Files;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Widget-ul de feedback (App\Support\FeedbackWidget): scriptul de pe site-ul clientului, activarea unui dispozitiv din cemainou și
 * primirea feedback-ului. Cererile de pe site vin cu cheia dispozitivului în antet (nu cu cookie-uri) și doar de pe domeniile permise.
 */
class WidgetController extends Controller
{
    /** Scriptul pentru site: pentru vizitatori nu face nimic; pe un dispozitiv activat arată butonul și panoul. */
    public function script(string $key): Response
    {
        $project = Project::where('widget_key', $key)->where('widget_enabled', true)->first();
        $locale = Settings::get('locale');
        $t = fn (string $text, array $r = []) => __($text, $r, $locale);
        $config = $project ? [
            'key' => $key,
            'api' => url('/api/widget/'.$key),
            'font' => ['latin' => route('widget.font', 'latin'), 'ext' => route('widget.font', 'latin-ext')],
            'project' => $project->name,
            'scope' => FeedbackWidget::scope($project),
            'i18n' => [
                'button' => $t('Feedback'), 'title' => $t('Feedback on :project', ['project' => $project->name]), 'placeholder' => $t('What did you notice? What should be different? The first line is the title.'),
                'shots' => $t('Add screenshots'), 'send' => $t('Send'), 'sending' => $t('Sending…'),
                'sent' => $t('Thank you, it is on the project in cemainou.'), 'open' => $t('Open it'), 'error' => $t('It did not go. Try again.'), 'empty' => $t('Write what you noticed.'),
                'as' => $t('As :name', ['name' => '{name}']), 'leave' => $t('Turn off on this device'), 'close' => $t('Close'),
                'new' => $t('New feedback'), 'sentTab' => $t('Sent'), 'nothingSent' => $t('Nothing sent from this site yet.'), 'point' => $t('Show on the page'),
                'pointHint' => $t('Click the element the feedback is about. Esc cancels.'), 'pointed' => $t('Element:'), 'pointMore' => $t('Show another one'),
                'shotMac' => $t('Take a screenshot (⌘ Shift 4, or ⌘ Ctrl Shift 4 to copy it) and paste it here with ⌘ V. You can draw on it.'), 'shotWin' => $t('Take a screenshot (Win Shift S) and paste it here with Ctrl V. You can draw on it.'), 'shotPhone' => $t('Take the screenshot with the phone buttons, then pick it in "Add screenshots" and draw on it. What you wrote stays.'), 'draw' => $t('Draw on it'), 'box' => $t('Box'), 'arrow' => $t('Arrow'), 'undo' => $t('Undo'), 'done' => $t('Done drawing'),
                'hide' => $t('Hide the button'), 'draft' => $t('Draft kept'), 'onPage' => $t('Feedback already sent here'),
                'doneTitle' => $t('Feedback sent'), 'openTask' => $t('Open the task'), 'another' => $t('Send another'), 'more' => $t('More options'),
                'number' => $t('Number'), 'text' => $t('Text'), 'red' => $t('Red'), 'yellow' => $t('Yellow'), 'textHint' => $t('Write, then Enter'), 'openHere' => $t(':count open on this page'), 'news' => $t('New answers from the team'), 'similar' => $t('Looks like something already open:'), 'samePage' => $t('this page'), 'addHere' => $t('Add it here'), 'notSame' => $t('It is something else'), 'addedHere' => $t('Added to #:id as a reply: the team sees it there.'), 'whoTitle' => $t('Who are you?'), 'whoText' => $t('Your name goes with what you send, so the team knows who to answer. Leave your email if you want to know when it is done.'), 'whoName' => $t('Your name'), 'whoEmail' => $t('Email (optional)'), 'whoSave' => $t('Continue'), 'whoNeed' => $t('Write your name.'), 'whoBad' => $t('Check the email address.'), 'reply' => $t('Reply'), 'replyPh' => $t('Write an answer…'), 'fixed' => $t('See the fix'), 'openIn' => $t('Open in cemainou'), 'noComments' => $t('No comments yet.'), 'here' => $t('Open on this page (:count)'),
            ],
        ] : null;
        $js = $config ? str_replace('__WSA_CONFIG__', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), file_get_contents(resource_path('widget/feedback.js'))) : '/* cemainou: widget off */';

        return response($js, 200, ['Content-Type' => 'application/javascript; charset=UTF-8', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Din cemainou (logat): un cod de unică folosință și site-ul deschis cu el după „#", ca butonul să apară pe acest dispozitiv. */
    public function activate(Request $request, Project $project, string $domain): View
    {
        $user = $request->user();
        abort_unless(FeedbackWidget::canUse($user, $project) && in_array($domain, $project->widget_domains ?? [], true), 403);

        return $this->confirm($project, $domain);
    }

    /**
     * Pasul de confirmare (audit 21 oct. 2026): codul contului tău pleacă spre site doar după ce vezi domeniul și apeși. Altfel un link primit
     * (de la cineva care conduce un proiect și i-a pus un domeniu al lui) ți-ar porni contul pe site-ul aceluia.
     */
    private function confirm(Project $project, string $domain, ?int $invite = null): View
    {
        return view('widget.confirm', ['domain' => $domain, 'project' => $project,
            'payload' => encrypt(['project' => $project->id, 'domain' => $domain, 'user' => auth()->id(), 'invite' => $invite, 'until' => now()->addMinutes(10)->getTimestamp()])]);
    }

    /** Apăsarea de pe pagina de confirmare: codul, pentru omul logat, și site-ul deschis cu el. */
    public function confirmed(Request $request): RedirectResponse
    {
        $data = rescue(fn () => decrypt((string) $request->input('payload')), null, false);
        $user = $request->user();
        $project = is_array($data) ? Project::find($data['project'] ?? 0) : null;
        abort_unless($project && $user && ($data['user'] ?? null) === $user->id && ($data['until'] ?? 0) >= now()->getTimestamp()
            && FeedbackWidget::canUse($user, $project) && in_array($data['domain'], $project->widget_domains ?? [], true), 403);

        return redirect()->away('https://'.$data['domain'].'/#wsa='.FeedbackWidget::code($user, $project, $data['domain']));
    }

    /**
     * „QR fără logare" scanat cu telefonul: o dată, 10 minute; deschide site-ul cu butonul pornit. Un telefon logat deja în cemainou
     * (cineva cu cont) pornește pe contul lui, fără „Cine ești?"; doar unul fără cont devine al unui vizitator, în numele celui care a dat codul.
     */
    public function qr(Request $request, string $token): RedirectResponse|View
    {
        $data = Cache::pull('cemainou.widget.qr.'.$token);
        $user = $data ? User::find($data['user']) : null;
        $project = $data ? Project::find($data['project']) : null;
        abort_unless($user && $project && FeedbackWidget::canUse($user, $project) && in_array($data['domain'], $project->widget_domains ?? [], true), 410);
        $me = $request->user();
        if ($me && FeedbackWidget::canUse($me, $project)) {                       // pe contul lui: doar după confirmare
            return $this->confirm($project, $data['domain']);
        }

        return redirect()->away('https://'.$data['domain'].'/#wsa='.FeedbackWidget::code($user, $project, $data['domain'], guest: true));
    }

    /**
     * O invitație la feedback (link sau QR, refolosibilă, cu durata ei): cine are cont și acces intră pe contul lui; restul, ca vizitator,
     * în numele celui care a dat linkul. Oprită, expirată sau folosită deja (cea de o dată): pagina „nu mai merge".
     */
    public function invite(Request $request, string $token): RedirectResponse|View
    {
        $invite = WidgetInvite::with(['project', 'user'])->where('token', $token)->first();
        $project = $invite?->project;
        abort_unless($invite && $invite->open() && $project && $invite->user && FeedbackWidget::canUse($invite->user, $project)
            && $project->widget_enabled && in_array($invite->domain, $project->widget_domains ?? [], true), 410);
        $invite->forceFill(['uses' => $invite->uses + 1, 'last_used_at' => now()])->save();
        $me = $request->user();
        if ($me && FeedbackWidget::canUse($me, $project)) {                       // pe contul lui: doar după confirmare
            return $this->confirm($project, $invite->domain);
        }

        return redirect()->away('https://'.$invite->domain.'/#wsa='.FeedbackWidget::code($invite->user, $project, $invite->domain, guest: true, invite: $invite->id));
    }

    /** Vizitatorul (QR fără logare) își spune numele și, dacă vrea, emailul: apar pe ce trimite; emailul primește vestea când e gata. */
    public function guest(Request $request, string $key): JsonResponse
    {
        $project = $this->project($key);
        $device = FeedbackWidget::device($project, $request->bearerToken(), $request->header('Origin'));
        abort_unless($device && $device->guest, 401);
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'nullable|email|max:255']);
        $device->forceFill(['guest_name' => trim($data['name']), 'guest_email' => filled($data['email'] ?? null) ? trim($data['email']) : null])->save();

        return response()->json($this->who($device, $project));
    }

    /**
     * Timpul în widget: cât timp panoul e deschis (sau omul arată un element, desenează pe o captură) și lucrează, un semnal pe minut, pe proiectul
     * widget-ului - același „în app" ca timpul din cemainou (un minut nu se numără de două ori). Nu pentru vizitatori, clienți sau cine l-a oprit.
     */
    public function presence(Request $request, string $key): JsonResponse
    {
        $project = $this->project($key);
        $device = FeedbackWidget::device($project, $request->bearerToken(), $request->header('Origin'));
        abort_unless($device, 401);
        $user = $device->user;
        if ($device->guest || $user->isClient() || ! $user->track_app) {
            return response()->json(['ok' => false]);
        }
        if (Cache::add('cemainou.app.beat.'.$user->id, 1, 50)) {             // aceeași cheie ca în aplicație: cemainou și widget-ul deodată fac un singur minut
            Activity::create(['user_id' => $user->id, 'project_id' => $project->id, 'kind' => ActivityKind::App, 'source' => Source::Web, 'occurred_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /** Scriptul schimbă codul pe cheia dispozitivului. */
    public function redeem(Request $request, string $key): JsonResponse
    {
        $project = $this->project($key);
        $result = FeedbackWidget::redeem($project, (string) $request->input('code'), (string) $request->header('Origin'), $request->userAgent());
        abort_unless($result, 403);

        return response()->json(['token' => $result['token']] + $this->who($result['device'], $project));
    }

    /** E dispozitivul încă activ? (scriptul întreabă cel mult de două ori pe zi) */
    public function me(Request $request, string $key): JsonResponse
    {
        $project = $this->project($key);
        $device = FeedbackWidget::device($project, $request->bearerToken(), $request->header('Origin'));
        abort_unless($device, 401);

        return response()->json($this->who($device, $project));
    }

    /** „Oprește pe acest dispozitiv": cheia nu mai merge. */
    public function forget(Request $request, string $key): JsonResponse
    {
        $device = FeedbackWidget::device($this->project($key), $request->bearerToken(), $request->header('Origin'));
        $device?->forceFill(['revoked_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Feedback-ul, mereu o sarcină: de la client, „de la client", la liderul proiectului (ca din cemainou); de la echipă, liberă pe proiect.
     * Primul rând e titlul, restul detaliile; contextul (pagina, browserul, ecranul, erorile, elementul arătat) stă separat, pe sarcină.
     */
    public function feedback(Request $request, string $key): JsonResponse
    {
        $project = $this->project($key);
        $device = FeedbackWidget::device($project, $request->bearerToken(), $request->header('Origin'));
        abort_unless($device, 401);
        $user = $device->user;
        abort_if($device->guest && ! $device->guest_name, 422, 'Name first.');           // vizitatorul își spune întâi numele
        $data = $request->validate([
            'text' => 'required|string|max:'.Task::MAX_NOTES,
            'context' => 'nullable|string|max:20000',
            'shots' => 'array|max:3',
            'shots.*' => ['file', 'max:'.Files::maxKb(), 'extensions:png,jpg,jpeg,webp,gif'],
        ]);

        $lines = preg_split('/\R/u', trim($data['text']));
        $title = Str::limit(trim(preg_replace('/^[#>*\-\s]+/u', '', $lines[0] ?? '')), 200, '') ?: __('Feedback from the site', [], $user->locale);
        $notes = trim(implode("\n", array_slice($lines, 1))) ?: null;
        $context = FeedbackWidget::context(json_decode((string) ($data['context'] ?? ''), true) ?: [], $request->userAgent())
            + ['device_id' => $device->id]
            + ($device->guest ? ['guest' => array_filter(['name' => $device->guest_name, 'email' => $device->guest_email])] : []);   // vizitatorul: cine a trimis de fapt
        $assignee = $project->lead?->is_active ? $project->lead : null;              // la liderul proiectului, de la oricine: să apară la cineva, nu liberă

        $task = Task::add($project, $user, $title, $notes, $assignee, source: Source::Web);
        $task->forceFill(['site_context' => $context ?: null])->save();
        foreach ($request->file('shots', []) as $shot) {
            Files::attach($project, $user, $shot, $shot->getClientOriginalName() ?: 'captura.png', null, Source::Web, $task);
        }

        return response()->json(['ok' => true, 'id' => $task->id, 'url' => TaskResource::getUrl('view', ['record' => $task])]);
    }

    /** „Ce am trimis": sarcinile omului trimise din widget pe acest proiect, cu starea lor. */
    public function mine(Request $request, string $key): JsonResponse
    {
        $project = $this->project($key);
        $device = FeedbackWidget::device($project, $request->bearerToken(), $request->header('Origin'));
        abort_unless($device, 401);

        return response()->json(['items' => Task::with('project')->where('project_id', $project->id)->where('created_by', $device->user_id)->whereNotNull('site_context')
            ->latest()->limit($device->guest ? 100 : 20)->get()
            ->when($device->guest, fn ($tasks) => $tasks->filter(fn (Task $t) => ($t->site_context['device_id'] ?? null) === $device->id)->take(20))   // vizitatorul: doar ce a trimis de pe telefonul lui
            ->map(fn (Task $t) => $this->item($t, $device->user->locale) + $this->reply($t, $device->user_id, $device->user->locale))->values()->all()]);
    }

    /**
     * Cât timp omul scrie: observațiile încă deschise ale proiectului care seamănă cu textul lui (cuvinte comune, fără diacritice și cuvinte
     * de umplutură; cele de pe aceeași pagină întâi), ca să adauge la una în loc să trimită o dublură. Vizitatorul vede doar ale lui.
     */
    public function similar(Request $request, string $key): JsonResponse
    {
        $project = $this->project($key);
        $device = FeedbackWidget::device($project, $request->bearerToken(), $request->header('Origin'));
        abort_unless($device, 401);
        $words = FeedbackWidget::words((string) $request->query('q'));
        if (count($words) < 2) {
            return response()->json(['items' => []]);
        }
        $path = '/'.ltrim((string) parse_url((string) $request->query('url'), PHP_URL_PATH), '/');
        $items = Task::visibleTo($device->user)->with('project')->where('project_id', $project->id)->open()->whereNotNull('site_context')->latest()->limit(200)->get()
            ->when($device->guest, fn ($tasks) => $tasks->filter(fn (Task $t) => ($t->site_context['device_id'] ?? null) === $device->id))
            ->map(function (Task $t) use ($words, $path) {
                $theirs = FeedbackWidget::words($t->title.' '.$t->notes);
                $common = count(array_intersect($words, $theirs));
                $score = $common >= 2 && $theirs ? $common / min(count($words), count($theirs)) + (($t->site_context['path'] ?? null) === $path ? 0.15 : 0) : 0;

                return ['task' => $t, 'score' => $score];
            })->filter(fn (array $r) => $r['score'] >= 0.5)->sortByDesc('score')->take(3)
            ->map(fn (array $r) => $this->item($r['task'], $device->user->locale) + ['same_page' => ($r['task']->site_context['path'] ?? null) === $path])->values()->all();

        return response()->json(['items' => $items]);
    }

    /** Conversația pe o observație trimisă din widget: comentariile, ca în cemainou (doar pe una pe care omul o vede, de pe acest proiect). */
    public function thread(Request $request, string $key, int $task): JsonResponse
    {
        [$device, $item] = $this->ownTask($request, $key, $task);
        $locale = $device->user->locale ?: config('app.locale');

        return response()->json(['comments' => $item->comments()->with('author')->get()->map(fn (TaskComment $c) => [
            'by' => $c->author?->name, 'mine' => $c->user_id === $device->user_id,
            'text' => Str::limit(trim(strip_tags(Str::markdown($c->body))), 1500),
            'when' => $c->created_at->locale($locale)->diffForHumans(),
        ])->all()]);
    }

    /** Un răspuns din widget: comentariu pe sarcină, în numele omului (aceleași reguli și notificări ca în cemainou). */
    public function answer(Request $request, string $key, int $task): JsonResponse
    {
        [$device, $item] = $this->ownTask($request, $key, $task);
        $data = $request->validate(['text' => 'required|string|max:5000']);
        abort_unless($device->user->can('comment', $item), 403);
        $body = $device->guest && $device->guest_name ? $device->guest_name.': '.trim($data['text']) : trim($data['text']);   // vizitatorul: numele lui în față
        $item->comments()->create(['user_id' => $device->user_id, 'body' => $body, 'source' => Source::Web]);

        return $this->thread($request, $key, $task);
    }

    /** @return array{0: WidgetDevice, 1: Task} dispozitivul și sarcina; una străină sau nevăzută e 404 */
    private function ownTask(Request $request, string $key, int $task): array
    {
        $project = $this->project($key);
        $device = FeedbackWidget::device($project, $request->bearerToken(), $request->header('Origin'));
        abort_unless($device, 401);
        $item = Task::visibleTo($device->user)->where('project_id', $project->id)->whereNotNull('site_context')->find($task);
        abort_unless($item && (! $device->guest || ($item->site_context['device_id'] ?? null) === $device->id), 404);   // vizitatorul: doar ale lui

        return [$device, $item];
    }

    /** Ce e deschis pe pagina asta: sarcinile încă deschise, trimise de pe această pagină, pe care omul le vede (cu elementul arătat, dacă e - pentru semne). */
    public function page(Request $request, string $key): JsonResponse
    {
        $project = $this->project($key);
        $device = FeedbackWidget::device($project, $request->bearerToken(), $request->header('Origin'));
        abort_unless($device, 401);
        $path = '/'.ltrim((string) parse_url((string) $request->query('url'), PHP_URL_PATH), '/');

        return response()->json(['items' => Task::visibleTo($device->user)->with('project')->where('project_id', $project->id)->open()->whereNotNull('site_context')
            ->latest()->limit(100)->get()
            ->filter(fn (Task $t) => ($t->site_context['path'] ?? null) === $path && (! $device->guest || ($t->site_context['device_id'] ?? null) === $device->id))   // vizitatorul: doar ale lui
            ->take(30)->map(fn (Task $t) => $this->item($t, $device->user->locale) + ['selector' => $t->site_context['element']['selector'] ?? null])->values()->all(),
            'news_at' => $this->newsAt($project, $device->user_id)]);
    }

    /** Fontul panoului (Inter Tight, ca în cemainou), servit de aici: pe site-urile clienților nu e încărcat, iar fără el panoul arăta altfel pe fiecare site. */
    public function font(string $file): BinaryFileResponse
    {
        return response()->file(resource_path('widget/fonts/inter-tight-'.$file.'-wght-normal.woff2'), [
            'Content-Type' => 'font/woff2', 'Cache-Control' => 'public, max-age=31536000, immutable', 'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** Miniatura din „Trimise": prima captură a sarcinii, printr-un link semnat (o imagine nu poate trimite cheia dispozitivului în antet). */
    public function shot(string $key, Attachment $attachment)
    {
        abort_unless($attachment->project_id === $this->project($key)->id && $attachment->isFile() && $attachment->isImage(), 404);
        $response = Files::response($attachment);
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');   // altfel imaginea nu se arată pe site-ul clientului

        return $response;
    }

    private function item(Task $task, ?string $locale): array
    {
        $shot = $task->attachments()->oldest('id')->get()->first(fn (Attachment $a) => $a->isFile() && $a->isImage());

        // „Vezi rezolvarea": închisă cu un link ca dovadă (pagina reparată)
        $fixed = $task->status === TaskStatus::Done && preg_match('#https?://[^\s)<>"]+#i', (string) $task->proof, $m) ? $m[0] : null;

        return ['thumb' => $shot ? URL::temporarySignedRoute('widget.shot', now()->addMinutes(30), ['key' => $task->project->widget_key, 'attachment' => $shot->id]) : null, 'id' => $task->id, 'title' => $task->title, 'status' => $task->status->value, 'label' => __($task->status->getLabel(), [], $locale), 'fixed_url' => $fixed,
            'when' => $task->created_at->locale($locale ?: config('app.locale'))->diffForHumans(), 'url' => TaskResource::getUrl('view', ['record' => $task])];
    }

    /** Ce i-a răspuns echipa omului care a trimis: ultimul comentariu al altcuiva și momentul ultimei noutăți (răspuns sau „Gata"), pentru semnul din „Trimise". */
    private function reply(Task $task, int $reporter, ?string $locale): array
    {
        $comment = $task->comments()->reorder()->where('user_id', '!=', $reporter)->with('author')->latest('id')->first();
        $news = max($comment?->created_at?->getTimestamp() ?? 0, $task->done_at?->getTimestamp() ?? 0);

        return ['reply' => $comment ? ['by' => $comment->author?->name, 'text' => Str::limit(trim(strip_tags(Str::markdown($comment->body))), 160),
            'when' => $comment->created_at->locale($locale ?: config('app.locale'))->diffForHumans()] : null, 'news_at' => $news ?: null];
    }

    /** Ultima noutate pe ce a trimis omul de pe site (un răspuns al echipei sau o sarcină terminată): cu ea, widgetul pune semnul pe buton și pe „Trimise". */
    private function newsAt(Project $project, int $reporter): ?int
    {
        $mine = Task::where('project_id', $project->id)->where('created_by', $reporter)->whereNotNull('site_context');
        $reply = TaskComment::whereIn('task_id', (clone $mine)->select('id'))->where('user_id', '!=', $reporter)->max('created_at');
        $done = (clone $mine)->max('done_at');
        $at = max($reply ? Carbon::parse($reply, 'UTC')->getTimestamp() : 0, $done ? Carbon::parse($done, 'UTC')->getTimestamp() : 0);

        return $at ?: null;
    }

    private function project(string $key): Project
    {
        return Project::where('widget_key', $key)->where('widget_enabled', true)->firstOrFail();
    }

    /** Cine trimite: omul cu cont sau, pe un telefon de vizitator, numele lui (prin omul care a dat codul). */
    private function who(WidgetDevice $device, Project $project): array
    {
        return $device->guest
            ? ['name' => $device->guest_name ? $device->guest_name.' · '.__('through :name', ['name' => $device->user->name], $device->user->locale) : null, 'project' => $project->name, 'guest' => true, 'needs_name' => ! $device->guest_name]
            : ['name' => $device->user->name, 'project' => $project->name];
    }
}
