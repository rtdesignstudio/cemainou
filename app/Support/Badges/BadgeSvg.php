<?php

namespace App\Support\Badges;

/**
 * Desenul unui badge: un blazon cu linie (sus drept, laturi drepte, jos o săgeată lată), dublat de un contur interior, hașurat fin înăuntru
 * (ca timpul adăugat de mână), iconița în mijloc (50 din 100) și, de la treapta II, un pătrat negru cu cifra romană peste vârf. Conturul are
 * exact grosimea liniei iconiței. Mic ($compact, sub ~40 px: lângă titlu, pe Azi): un singur contur, mai gros, cu hașura până la el.
 */
class BadgeSvg
{
    private const SHIELD = 'M12 6 H88 V78 L50 91 L12 78 Z';

    private const SCALE = 50 / 24;                                        // iconița: 24 de unități, întinse pe 50 din 100

    public static function render(string $icon, int $tier = 1, string $class = 'wsa-badge', bool $locked = false, ?string $label = null, bool $compact = false): string
    {
        $paths = Catalog::ICONS[$icon] ?? Catalog::ICONS['question'];
        $line = 1.3 * self::SCALE;                                        // grosimea liniei iconiței, în unitățile scutului
        $f = fn (float $n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        $half = 12 * self::SCALE;
        $svg = '<svg class="'.e($class).($locked ? ' is-locked' : '').'" viewBox="0 0 100 100" '.($label ? 'role="img" aria-label="'.e($label).'"' : 'aria-hidden="true"').'>'
            .'<defs><pattern id="wsa-badge-hatch" width="4" height="4" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="4" height="4" fill="#fff"/><rect width=".9" height="4" fill="#ebecef"/></pattern></defs>'
            .($compact
                ? '<path d="'.self::SHIELD.'" fill="url(#wsa-badge-hatch)" stroke="currentColor" stroke-width="'.$f($line * 1.6).'" stroke-linejoin="miter" stroke-miterlimit="10"/>'
                : '<path d="'.self::SHIELD.'" fill="none" stroke="currentColor" stroke-width="'.$f($line).'" stroke-linejoin="miter" stroke-miterlimit="10"/>'
                    .'<path d="'.self::SHIELD.'" transform="translate(50 48) scale(.88) translate(-50 -48)" fill="url(#wsa-badge-hatch)" stroke="currentColor" stroke-width="'.$f($line / .88).'" stroke-linejoin="miter" stroke-miterlimit="10"/>')
            .'<g transform="translate('.$f(50 - $half).' '.$f(45 - $half).') scale('.$f(self::SCALE).')" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="square" stroke-linejoin="miter">'.$paths.'</g>';
        if ($tier > 1) {
            $w = $tier === 3 ? 30 : 23;
            $svg .= '<rect x="'.(50 - $w / 2).'" y="75" width="'.$w.'" height="20" fill="currentColor"/>'
                .'<text x="50" y="89.4" text-anchor="middle" font-size="12.5" font-weight="700" letter-spacing=".5" fill="#fff" font-family="inherit">'.Badges::roman($tier).'</text>';
        }

        return $svg.'</svg>';
    }

    /** Pentru tooltip: „Serie II · 20 de zile: Update în fiecare zi de lucru..." (secretele nedescoperite: doar „Secret"). */
    public static function tip(string $key, int $tier = 1, bool $locked = false): string
    {
        $b = Catalog::get($key);
        if ($locked && ($b['secret'] ?? false)) {
            return __('Secret').': '.__('It shows itself when you get it.');
        }

        return Catalog::name($key).($tier > 1 ? ' '.Badges::roman($tier) : '').(count(Catalog::levels($key)) > 1 || $locked ? ' · '.Catalog::levelLabel($key, max(1, $tier)) : '')
            .': '.Catalog::criterion($key);
    }

    /** Un badge din catalog, la treapta dată (secretele nedescoperite: semnul întrebării). */
    public static function badge(string $key, int $tier = 1, string $class = 'wsa-badge', bool $locked = false, bool $compact = false): string
    {
        $b = Catalog::get($key);
        $hidden = $locked && ($b['secret'] ?? false);

        return self::render($hidden ? 'question' : ($b['icon'] ?? 'question'), $tier, $class, $locked, $hidden ? __('Secret') : Catalog::name($key), $compact);
    }
}
