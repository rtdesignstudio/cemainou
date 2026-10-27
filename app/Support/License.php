<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * cemainou e gratuit pentru orice echipă, cu oricâți oameni (LICENSE.md: FSL, doar fără un produs concurent). Cine vrea, îl susține (COMMERCIAL.md):
 * primește o cheie de susținător semnată de RT (Ed25519, cheia publică în config: cemainou.license_public_key), legată de numărul instalării
 * (FeedbackUpstream::installation). Cheia nu deblochează nimic: spune doar „Susținător", pentru cine și până când. Nimic nu se oprește fără ea,
 * nici actualizările. Adminii unei echipe de peste 5 oameni, după 30 de zile, sunt întrebați blând o dată pe lună (Widgets\SupportNudge).
 *
 * Forma cheii: CMN1.<payload base64url>.<semnătura base64url>, payload = {"id","licensee","installation","until"} (JSON); „people" (o limită de
 * oameni) e opțional, iar o cheie fără „installation" merge pe orice instalare (doar cheile RT de dinainte de licența pe instalare).
 */
class License
{
    public const PREFIX = 'CMN1';

    /** De la câți oameni se întreabă echipa, blând, dacă vrea să susțină (5). */
    public static function free(): int
    {
        return (int) config('cemainou.free_people', 5);
    }

    /** Oamenii care contează: conturile active din echipă (adminii, liderii, membrii, colaboratorii), fără clienți. */
    public static function people(): int
    {
        return User::query()->team()->count();
    }

    /** Numărul acestei instalări (aleator, făcut o dată): se trimite la cumpărare, iar cheia se leagă de el. */
    public static function installation(): string
    {
        return FeedbackUpstream::installation();
    }

    /**
     * Cheia, verificată: null dacă e goală, stricată sau nesemnată de RT.
     *
     * @return array{id: string, licensee: string, installation: ?string, people: ?int, until: Carbon}|null
     */
    public static function read(?string $key): ?array
    {
        $parts = explode('.', trim((string) $key));
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }
        $payload = self::decode($parts[1]);
        $signature = self::decode($parts[2]);
        $public = base64_decode((string) config('cemainou.license_public_key'), true);
        if ($payload === null || $signature === null || $public === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || ! sodium_crypto_sign_verify_detached($signature, self::PREFIX.'.'.$parts[1], $public)) {
            return null;
        }
        $data = json_decode($payload, true);
        if (! is_array($data) || ! is_string($data['licensee'] ?? null) || ! is_string($data['until'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['until'])
            || (isset($data['people']) && ! is_int($data['people'])) || (isset($data['installation']) && ! is_string($data['installation']))) {
            return null;
        }

        return ['id' => (string) ($data['id'] ?? ''), 'licensee' => $data['licensee'], 'installation' => $data['installation'] ?? null,
            'people' => $data['people'] ?? null, 'until' => Carbon::parse($data['until'])->endOfDay()];
    }

    /** O cheie nouă (scripts/release-tools.php license), semnată cu cheia secretă a RT; pentru o instalare, cu oricâți oameni ($people = null). */
    public static function issue(string $id, string $licensee, ?string $installation, string $until, string $secretKey, ?int $people = null): string
    {
        $payload = self::encode(json_encode(array_filter(['id' => $id, 'licensee' => $licensee, 'installation' => $installation, 'people' => $people, 'until' => $until],
            fn ($value) => $value !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::PREFIX.'.'.$payload.'.'.self::encode(sodium_crypto_sign_detached(self::PREFIX.'.'.$payload, $secretKey));
    }

    /**
     * Unde e instalarea: free (fără cheie), supporter (cheia e a ei și e în termen), expired (susținerea s-a încheiat), other (cheia altei instalări).
     *
     * @return array{state: string, people: int, licensee: ?string, until: ?Carbon}
     */
    public static function status(): array
    {
        $key = self::read(Settings::get('license_key'));
        $mine = $key !== null && ($key['installation'] === null || hash_equals($key['installation'], self::installation()));
        $state = match (true) {
            $key === null => 'free',
            ! $mine => 'other',
            $key['until']->isPast() => 'expired',
            default => 'supporter',
        };

        return ['state' => $state, 'people' => self::people(), 'licensee' => $key['licensee'] ?? null, 'until' => $key['until'] ?? null];
    }

    public static function supporter(): bool
    {
        return self::status()['state'] === 'supporter';
    }

    /** Nimic nu se mai oprește fără cheie: rămâne pentru codul vechi care întreabă. */
    public static function blocksUpdates(): bool
    {
        return false;
    }

    /** O cheie care nu e a instalării (copiată de altundeva): adminii o văd în Setări. Altfel nimic. */
    public static function problem(): ?string
    {
        return self::status()['state'] === 'other'
            ? __('The supporter key in Settings belongs to another installation. Ask for one for this installation (:id).', ['id' => self::installation()])
            : null;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
