<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\InlinesOnly\InlinesOnlyExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown-ul echipei, transformat în HTML sigur: HTML-ul scris în text se arată ca text, linkurile javascript: / data: nu trec,
 * linkurile spre alte site-uri se deschid în alt tab. Trei forme:
 *  - html(): complet (titluri, liste, tabele, bife, cod) - documente, notele echipei, notele sarcinilor; stilul .wsa-prose;
 *  - inline(): doar formatarea din rând (**bold**, *italic*, `cod`, ~~tăiat~~, linkuri) - rezumate, rândurile update-urilor, motive de blocare, focus;
 *    într-un text care e deja link (rândurile din liste), links: false lasă din linkuri doar textul, ca să nu fie link în link;
 *  - plain(): textul fără formatare - titlul din tab, căutare, subtitluri.
 */
class Markdown
{
    private static ?MarkdownConverter $inline = null;

    public static function html(?string $text, bool $breaks = false): HtmlString
    {
        return new HtmlString(Mentions::highlight(Str::markdown((string) $text, [
            ...self::safety(),
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => $breaks ? "<br>\n" : "\n"],        // în note, rândul nou rămâne rând nou, ca până acum
        ], [new ExternalLinkExtension])));                                   // @Ana: link spre pagina omului
    }

    public static function inline(?string $text, bool $links = true): HtmlString
    {
        $text = (string) $text;
        if (! preg_match('/[*_`~\[\]<>&@\\:!#|]|www\./', $text)) {                     // text simplu (cele mai multe rânduri): nimic de convertit
            return new HtmlString(e(trim($text)));
        }
        self::$inline ??= new MarkdownConverter(tap(new Environment([...self::safety(), 'max_nesting_level' => 10]), function (Environment $environment) {
            $environment->addExtension(new InlinesOnlyExtension);
            $environment->addExtension(new StrikethroughExtension);
            $environment->addExtension(new AutolinkExtension);
            $environment->addExtension(new ExternalLinkExtension);
        }));

        $html = trim((string) self::$inline->convert((string) $text));

        return new HtmlString($links ? Mentions::highlight($html) : preg_replace('#</?a\b[^>]*>#i', '', $html));
    }

    public static function plain(?string $text): string
    {
        return trim(html_entity_decode(strip_tags((string) self::inline($text, links: false)), ENT_QUOTES | ENT_HTML5));
    }

    private static function safety(): array
    {
        return [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'external_link' => [
                'internal_hosts' => [(string) parse_url((string) config('app.url'), PHP_URL_HOST)],
                'open_in_new_window' => true,
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ];
    }
}
