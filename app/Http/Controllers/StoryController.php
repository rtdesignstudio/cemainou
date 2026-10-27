<?php

namespace App\Http\Controllers;

use App\Support\WeekStory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** „Săptămâna în 30 de secunde": povestea omului (implicit), a echipei (?team=1, pentru echipă) sau a clientului (pentru oamenii clienților). */
class StoryController
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $team = $request->boolean('team') && $user->seesWholeTeam();
        $slides = $user->isClient() ? WeekStory::forClient($user) : ($team ? WeekStory::forTeam($user) : WeekStory::forPerson($user));

        return view('story.week', ['slides' => $slides, 'back' => url('/'), 'other' => ! $user->isClient() && $user->seesWholeTeam() ? route('story', $team ? [] : ['team' => 1]) : null, 'team' => $team]);
    }
}
