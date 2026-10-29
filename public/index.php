<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// OPcache verifică fișierele de cod doar o dată la un minut (opcache.revalidate_freq din .user.ini): mult mai repede pe hostingul comun.
// După o actualizare (Updater lasă semnul), cache-ul se golește o dată, la prima cerere, ca să nu rămână cod vechi.
if (function_exists('opcache_reset') && is_file($stamp = __DIR__.'/../bootstrap/cache/opcache-stamp') && @file_get_contents($stamp) !== @file_get_contents($stamp.'.done')) {
    @copy($stamp, $stamp.'.done');
    @opcache_reset();
    foreach (glob(__DIR__.'/../bootstrap/cache/*.php') ?: [] as $cached) {
        @opcache_invalidate($cached, true);   // opcache_reset se aplică abia după cererea asta: cache-urile proaspete le citește și ea
    }
}

// Mentenanța (artisan down, în timpul unei actualizări): răspunde 503 înainte să pornească aplicația, ca nicio cerere să nu dea peste
// cache-urile golite (maintenance.php din Laravel lasă cererea să treacă mai departe când nu e o pagină pregătită cu --render, și atunci
// aplicația pornea fără events.php). storage/ stă în data/ când există (App\Support\Installer::paths), deci semnul e căutat acolo.
$storage = is_dir(__DIR__.'/../data/storage/framework') ? __DIR__.'/../data/storage' : __DIR__.'/../storage';
if (is_file($storage.'/framework/down')) {
    http_response_code(503);
    header('Retry-After: 30');
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>cemainou</title>'
        .'<p style="font:16px system-ui,sans-serif;margin:3rem auto;max-width:30rem;padding:0 1rem">Updating, back in a few seconds.</p>';
    exit;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
