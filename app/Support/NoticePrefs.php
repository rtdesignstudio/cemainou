<?php

namespace App\Support;

use App\Models\User;

/**
 * Ce notificări primește fiecare, pe tipuri și pe canale: clopoțelul (în aplicație), telefonul / calculatorul (Web Push) și, cine și-a legat
 * contul, Telegram în privat.
 * Implicit, toate pornite; omul le oprește din Profil → Notificări. Emailurile au comutatoarele lor (rezumatul săptămânal, sarcinile).
 */
class NoticePrefs
{
    public const CHANNELS = ['bell', 'push', 'telegram'];

    /** Canalele omului: Telegram doar dacă și-a legat contul. @return list<string> */
    public static function channels(User $user): array
    {
        return $user->telegram_chat_id ? self::CHANNELS : ['bell', 'push'];
    }

    /** @return array<string, array{label: string, hint: string}> tipurile pe care le vede omul (clientul doar ce-l privește, adminul și feedbackul) */
    public static function types(User $user): array
    {
        $all = [
            'tasks' => ['label' => __('Tasks for you'), 'hint' => __('A colleague gives you a task; a client adds one on a project you lead.')],
            'done' => ['label' => __('Tasks done'), 'hint' => __('A task you asked for, or yours, is done by someone else.')],
            'comments' => ['label' => __('Comments'), 'hint' => __('On your tasks and updates, or where you wrote.')],
            'mentions' => ['label' => __('Mentions'), 'hint' => __('Someone writes @your name.')],
            'morning' => ['label' => __('Morning summary'), 'hint' => __('After 8: your tasks, what is new for you, blockers and yesterday\'s updates on the projects you follow.')],
            'reports' => ['label' => __('Monthly reports'), 'hint' => __('On the 1st, the report of last month is ready for the client projects you lead.')],
            'errors' => ['label' => __('Server errors'), 'hint' => __('In the morning, the errors from the day before, grouped.')],
            'badges' => ['label' => __('Badges'), 'hint' => __('A colleague says well done for one of your badges.')],
            'feedback' => ['label' => __('Feedback'), 'hint' => __('Feedback about cemainou from the team, and the answer to yours.')],
            'access' => ['label' => __('Outside access'), 'hint' => __('A client or collaborator opens something they should not see, or tries pages that do not exist. Also by email.')],
        ];
        $keys = match (true) {
            $user->isClient() => ['done', 'comments'],
            $user->isAdmin() => array_keys($all),
            default => ['tasks', 'done', 'comments', 'mentions', 'morning', 'reports', 'feedback', 'badges'],
        };

        return array_intersect_key($all, array_flip($keys));
    }

    public static function wants(User $user, string $type, string $channel): bool
    {
        return (bool) ($user->notice_prefs[$type][$channel] ?? true);
    }

    public static function set(User $user, string $type, string $channel, bool $on): void
    {
        abort_unless(array_key_exists($type, self::types($user)) && in_array($channel, self::CHANNELS, true), 400);
        $prefs = $user->notice_prefs ?? [];
        $prefs[$type][$channel] = $on;
        $user->forceFill(['notice_prefs' => $prefs]);
        if ($type === 'morning') {                                                     // rezumatul pleacă doar dacă are cel puțin un canal
            $user->forceFill(['wants_morning' => self::wants($user, 'morning', 'bell') || self::wants($user, 'morning', 'push') || ($user->telegram_chat_id && self::wants($user, 'morning', 'telegram'))]);
        }
        $user->save();
    }
}
