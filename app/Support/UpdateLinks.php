<?php

namespace App\Support;

use App\Models\Project;

/**
 * Linkurile unui update, gata de afișat: hash-urile de commit duc la commit pe GitHub / GitLab (când repo-ul proiectului e acolo),
 * adresele apar scurtate, orice alt text rămâne text.
 */
class UpdateLinks
{
    public const LABEL_MAX = 60;

    /** @return list<array{label: string, url: ?string, commit: bool}> */
    public static function resolve(array $links, ?Project $project): array
    {
        $resolved = [];
        foreach ($links as $link) {
            $link = trim((string) $link);
            if ($link === '') {
                continue;
            }

            $resolved[] = match (true) {
                (bool) preg_match('/^[0-9a-f]{7,40}$/i', $link) => ['label' => $link, 'url' => self::commitUrl($link, $project?->repo_key), 'commit' => true],
                (bool) preg_match('#^https?://#i', $link) => ['label' => self::shorten($link), 'url' => $link, 'commit' => false],
                default => ['label' => $link, 'url' => null, 'commit' => false],
            };
        }

        return $resolved;
    }

    public static function commitUrl(string $hash, ?string $repoKey): ?string
    {
        return match (true) {
            str_starts_with((string) $repoKey, 'github.com/') => "https://{$repoKey}/commit/{$hash}",
            str_starts_with((string) $repoKey, 'gitlab.com/') => "https://{$repoKey}/-/commit/{$hash}",
            default => null,
        };
    }

    /** Fără https:// și www., cel mult 60 de caractere. */
    private static function shorten(string $url): string
    {
        $label = rtrim((string) preg_replace('#^https?://(www\.)?#i', '', $url), '/');

        return mb_strlen($label) > self::LABEL_MAX ? mb_substr($label, 0, self::LABEL_MAX - 1).'…' : $label;
    }
}
