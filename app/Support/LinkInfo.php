<?php

namespace App\Support;

/**
 * Ce se știe despre un link lipit: serviciul (Figma, Google Drive, Loom, un demo pe Vercel...) și titlul paginii, citit de server
 * în siguranță (RemoteFile::page: doar https, fără adrese private). Titlul se completează singur când omul nu scrie unul.
 */
class LinkInfo
{
    /** Domeniul (sau sfârșitul lui) => numele serviciului. */
    public const SERVICES = [
        'figma.com' => 'Figma', 'docs.google.com' => 'Google Docs', 'drive.google.com' => 'Google Drive', 'sheets.google.com' => 'Google Sheets',
        'slides.google.com' => 'Google Slides', 'loom.com' => 'Loom', 'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube', 'vimeo.com' => 'Vimeo',
        'github.com' => 'GitHub', 'gitlab.com' => 'GitLab', 'vercel.app' => 'Vercel', 'netlify.app' => 'Netlify', 'notion.so' => 'Notion',
        'notion.site' => 'Notion', 'miro.com' => 'Miro', 'behance.net' => 'Behance', 'dribbble.com' => 'Dribbble', 'dropbox.com' => 'Dropbox',
        'canva.com' => 'Canva', 'framer.app' => 'Framer', 'framer.website' => 'Framer', 'webflow.io' => 'Webflow', 'onedrive.live.com' => 'OneDrive',
        'sharepoint.com' => 'SharePoint', 'wetransfer.com' => 'WeTransfer',
    ];

    public static function service(string $url): ?string
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
        foreach (self::SERVICES as $domain => $name) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $name;
            }
        }

        return null;
    }

    /** Titlul paginii (og:title, apoi <title>), curățat; altfel serviciul și domeniul („Figma · figma.com"). */
    public static function title(string $url): string
    {
        $html = str_starts_with(strtolower(trim($url)), 'https://') ? RemoteFile::page(trim($url)) : null;
        $title = null;
        if ($html) {
            if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
                || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', $html, $m)
                || preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
                $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }
        }
        $host = preg_replace('/^www\./', '', (string) parse_url(trim($url), PHP_URL_HOST));

        return mb_substr($title ?: (self::service($url) ? self::service($url).' · '.$host : $host), 0, 160);
    }
}
