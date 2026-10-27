<?php

namespace App\Support;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Commands;
use App\Mail\SetupStepsMail;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * „Trimite-i pașii" (Echipa azi): adminul vede la un coleg unelte gri sau rămase în urmă și îi trimite, pe Telegram (dacă și-l legase) sau pe email,
 * doar pașii care îi lipsesc: conectarea calculatorului (comanda se ia din profil, cu cheia lui: nu pleacă nicio cheie pe email), cum merge /upd,
 * aplicația Claude, telefonul, notificările, Telegram. Fără Telegram și fără email, în clopoțel. O dată pe zi și om.
 */
class SetupSteps
{
    /** @param  list<array{key: string, state: string}>  $setup  @return list<array{title: string, text: string, url: string}> */
    public static function for(User $person, array $setup, string $locale): array
    {
        $missing = collect($setup)->reject(fn (array $s) => $s['state'] === 'ok')->pluck('state', 'key');
        $profile = EditProfile::getUrl(panel: 'admin');
        $t = fn (string $text, array $replace = []) => __($text, $replace, $locale);
        $steps = [];
        if ($missing->has('agent') || $missing->has('time')) {
            $steps[] = ['title' => $t('Connect your computer'), 'url' => $profile.'?connect=computer',   // linkul deschide direct comanda, gata de copiat (cheia se creează abia când rulează)
                'text' => $missing->has('agent') ? $t('Open the link on the computer where you work: the command is ready. Paste the message in your agent\'s chat (Claude Code or the Code tab of the Claude app) and it connects everything, once per computer. It measures your time and lets the agent report for you.')
                    : $t('Your time is not measured lately: open the link and paste the message in your agent\'s chat again; it puts the hooks back.')];
        }
        if ($missing->has('upd') || $missing->has('agent')) {
            $steps[] = ['title' => $t('Report with /upd'), 'url' => Commands::getUrl(panel: 'admin'),
                'text' => $t('When you finish a piece of work, type /upd in the agent: it gathers what you did from the conversation and from git, shows you the draft, and sends it only after your yes. The first time you type it yourself; then it reminds you in the afternoon.')];
        }
        if ($missing->has('app')) {
            $steps[] = ['title' => $t('Link the Claude app'), 'url' => $profile.'?connect=claude-app',
                'text' => $t('The link opens the steps: add cemainou as a connector in the Claude app, then ask Claude about your tasks and projects from the phone or the desktop app.')];
        }
        if ($missing->has('phone')) {
            $steps[] = ['title' => $t('Put cemainou on your phone'), 'url' => url('/'),
                'text' => $t('Open cemainou in the phone\'s browser and choose Add to Home Screen: it opens like an app.')];
        }
        if ($missing->has('push')) {
            $steps[] = ['title' => $t('Turn on notifications'), 'url' => $profile,
                'text' => $t('In your profile → Notifications → Turn on, on each device: tasks for you, comments and what the client asks, even with cemainou closed.')];
        }
        if ($missing->has('telegram')) {
            $steps[] = ['title' => $t('Connect Telegram'), 'url' => $profile,
                'text' => $t('In your profile → Telegram → Connect: your notifications come there too, and a reply to one becomes a comment.')];
        }

        return $steps;
    }

    public static function sentToday(User $person): bool
    {
        return Cache::has(self::key($person));
    }

    /** Trimite pașii; întoarce canalul ('telegram', 'email', 'bell') sau null dacă nu e nimic de trimis. */
    public static function send(User $person, User $by, array $setup): ?string
    {
        $locale = $person->preferredLocale();
        $steps = self::for($person, $setup, $locale);
        if ($steps === []) {
            return null;
        }
        $channel = null;
        if ($person->telegram_chat_id && Telegram::configured()) {
            $html = '<b>'.e(__(':name sent you the steps to set up cemainou', ['name' => $by->name], $locale)).'</b>';
            foreach ($steps as $i => $step) {
                $html .= "\n\n<b>".($i + 1).'. '.e($step['title']).'</b>'."\n".e($step['text'])."\n".'<a href="'.e($step['url']).'">'.e(__('Open it', [], $locale)).'</a>';
            }
            try {
                Telegram::send($person->telegram_chat_id, $html);
                $channel = 'telegram';
            } catch (Throwable $e) {
                report($e);
            }
        }
        if (! $channel && TeamMail::ready()) {
            Mail::to($person)->send(new SetupStepsMail($person, $by, $steps));
            $channel = 'email';
        }
        if (! $channel) {
            Inbox::notify($person, __(':name sent you the steps to set up cemainou', ['name' => $by->name], $locale),
                collect($steps)->map(fn (array $s, int $i) => ($i + 1).'. '.$s['title'])->join(' · '), $steps[0]['url'], Heroicon::OutlinedRocketLaunch, $locale, 'tasks');
            $channel = 'bell';
        }
        Cache::put(self::key($person), true, now()->endOfDay());

        return $channel;
    }

    private static function key(User $person): string
    {
        return 'cemainou.setup-steps.'.$person->id.'.'.now()->toDateString();
    }
}
