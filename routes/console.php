<?php

use App\Support\Housekeeping;
use App\Support\WeeklyDigest;
use Illuminate\Support\Facades\Schedule;

// pentru cine are cron (opțional): aceeași curățenie zilnică pe care o rulează și prima cerere din zi
Schedule::call(fn () => app(Housekeeping::class)->runIfDue())->daily()->name('cemainou-housekeeping');
Schedule::call(fn () => app(WeeklyDigest::class)->sendIfDue())->hourly()->name('cemainou-weekly-digest');   // cu cron: rezumatul pleacă la ora lui, nu la prima cerere
// instanța demo publică: datele inventate se refac în fiecare oră (ce lasă cineva acolo, de ex. linkuri, nu stă mult) (doar cu CEMAINOU_DEMO=true)
Schedule::command('cemainou:demo-reset')->hourly()->when(fn () => (bool) config('cemainou.demo'))->name('cemainou-demo-reset')->withoutOverlapping(30);

// doar la RT: câte instalări verifică versiunile, din jurnalele rt.md (nimic de la instanțe)
Schedule::command('cemainou:install-estimate')->dailyAt('05:30')->timezone('Europe/Chisinau')->when(fn () => filled(config('cemainou.install_logs')));

// doar la RT: rezumatul săptămânii din Umami, luni dimineața, ca notiță pe proiect
Schedule::command('cemainou:umami-weekly')->weeklyOn(1, '08:00')->timezone('Europe/Chisinau')->when(fn () => filled(config('cemainou.umami_summary_ssh')));
