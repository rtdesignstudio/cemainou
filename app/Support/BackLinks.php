<?php

namespace App\Support;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;

/**
 * Drumul înapoi de deasupra titlului („← Studio Nord / Site Studio Nord"). O pagină îl dă din backLinks(): list<array{label, url}>;
 * îl desenează un render hook (AdminPanelProvider) cu filament.partials.back-links.
 */
class BackLinks
{
    /** Clientul (pentru echipă, la pagina lui) și proiectul. */
    public static function project(Project $project, User $viewer): array
    {
        $project->loadMissing('client');
        $links = $project->client && ! $viewer->isClient() ? [['label' => $project->client->name, 'url' => ClientResource::getUrl('view', ['record' => $project->client])]] : [];

        return [...$links, ['label' => $project->name, 'url' => ProjectResource::getUrl('view', ['record' => $project])]];
    }

    /** Doar o pagină: lista sau înregistrarea de la care s-a venit. */
    public static function to(string $label, string $url): array
    {
        return [['label' => $label, 'url' => $url]];
    }
}
