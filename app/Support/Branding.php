<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Compania care folosește cemainou: numele și logo-ul (SVG sau PNG), încărcate de admin în Setări → Companie.
 * Logo-ul stă în data/files/branding și se servește public la /brand/logo (nu e un secret; în pagini doar prin <img>).
 */
class Branding
{
    public const TYPES = ['svg' => 'image/svg+xml', 'png' => 'image/png'];

    public static function name(): ?string
    {
        return Settings::get('company_name');
    }

    /** Calea logo-ului pe discul data, doar dacă fișierul chiar există. */
    public static function logoPath(): ?string
    {
        $path = Settings::get('company_logo');

        return $path && isset(self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))]) && Storage::disk('data')->exists($path) ? $path : null;
    }

    /** Adresa logo-ului, cu ?v= după fișier: un logo nou se vede imediat, cel vechi rămâne în cache cât e valabil. */
    public static function logoUrl(): ?string
    {
        return ($path = self::logoPath()) ? route('brand.logo', ['v' => substr(md5($path), 0, 8)]) : null;
    }
}
