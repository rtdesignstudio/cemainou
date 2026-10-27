<?php

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Semnătura unei arhive de versiune, în arhivă: release.json (versiunea, PHP-ul minim și sha256-ul fiecărui fișier) și release.sig (semnătura RT,
 * Ed25519, peste release.json). scripts/release.sh o pune la construire, cu cheia secretă din ~/.config/cemainou-release (niciodată în proiect);
 * aplicația o verifică după dezarhivare, cu cheia publică din config, fișier cu fișier: nimic în plus, nimic lipsă, nimic schimbat.
 */
class ReleaseManifest
{
    public const FILE = 'release.json';

    public const SIGNATURE = 'release.sig';

    /** Scrie release.json și release.sig într-un folder de versiune gata de arhivat. */
    public static function build(string $dir, string $version, string $secretKey, string $php = '8.4'): void
    {
        $manifest = ['name' => 'cemainou', 'version' => $version, 'php' => $php, 'files' => self::hashes($dir)];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents("{$dir}/".self::FILE, $json);
        file_put_contents("{$dir}/".self::SIGNATURE, base64_encode(sodium_crypto_sign_detached($json, $secretKey))."\n");
    }

    /**
     * Verifică un folder dezarhivat: semnătura peste release.json, apoi fiecare fișier. Întoarce release.json; o nepotrivire aruncă excepție.
     *
     * @return array{name: string, version: string, php: string, files: array<string, string>}
     */
    public static function verify(string $dir, string $publicKey): array
    {
        $json = @file_get_contents("{$dir}/".self::FILE);
        $signature = base64_decode(trim((string) @file_get_contents("{$dir}/".self::SIGNATURE)), true);
        $key = base64_decode($publicKey, true);
        if ($json === false || $signature === false || $key === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($signature, $json, $key)) {
            throw new RuntimeException(__('The archive is not signed by RT Design Studio; nothing was changed.'));
        }
        $manifest = json_decode($json, true);
        if (! is_array($manifest) || ! in_array($manifest['name'] ?? null, ['whatsupai', 'cemainou'], true) || ! is_string($manifest['version'] ?? null) || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException(__('The archive has no valid file list; nothing was changed.'));
        }
        $found = self::hashes($dir);
        ksort($manifest['files']);
        if ($found !== $manifest['files']) {
            throw new RuntimeException(__('The archive was changed after it was signed; nothing was changed.'));
        }

        return $manifest;
    }

    /** sha256 pentru fiecare fișier din folder (căi relative, sortate), fără fișierele semnăturii. @return array<string, string> */
    private static function hashes(string $dir): array
    {
        $files = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($items as $item) {
            $path = substr($item->getPathname(), strlen($dir) + 1);
            if ($item->isLink()) {
                throw new RuntimeException(__('The archive has a link instead of a file; nothing was changed.'));
            }
            if ($item->isFile() && $path !== self::FILE && $path !== self::SIGNATURE) {
                $files[str_replace('\\', '/', $path)] = hash_file('sha256', $item->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
