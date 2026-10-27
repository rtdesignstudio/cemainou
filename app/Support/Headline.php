<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/** Titlul unei pagini de obiect (rezumatul unui update, titlul unei sarcini): cu cât e mai lung, cu atât mai mic (trei trepte), ca să rămână un titlu, nu un paragraf. */
class Headline
{
    /** Cu markdown: true, formatarea din rând (bold, `cod`, linkuri) se păstrează, iar lungimea se măsoară pe textul fără ea. */
    public static function html(string $text, bool $markdown = false): HtmlString
    {
        $length = mb_strlen($markdown ? Markdown::plain($text) : $text);
        $size = match (true) {
            $length > 140 => ' is-longer',
            $length > 60 => ' is-long',
            default => '',
        };

        return new HtmlString('<span class="wsa-headline'.$size.'">'.($markdown ? Markdown::inline($text) : e($text)).'</span>');
    }
}
