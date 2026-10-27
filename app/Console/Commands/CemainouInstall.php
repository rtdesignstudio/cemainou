<?php

namespace App\Console\Commands;

use App\Support\Installer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Instalarea din terminal (VPS, Docker, instalări automate): aceleași date și aceeași logică ca pagina /install.
 * Parolele nu stau în argumente (ar rămâne în istoricul shell-ului și în lista de procese): se cer ascuns, vin din
 * CEMAINOU_ADMIN_PASSWORD / CEMAINOU_DB_PASSWORD, sau parola adminului se generează (--generate-password) și se afișează o singură dată.
 */
#[Signature('cemainou:install
    {--url= : Adresa echipei, de ex. https://cemainou.firma.md}
    {--email= : Emailul primului admin}
    {--name= : Numele primului admin}
    {--locale=ro : Limba echipei (ro sau en)}
    {--timezone=Europe/Chisinau : Fusul orar al echipei}
    {--db=sqlite : Baza de date: sqlite (implicit, fără nimic de configurat) sau mysql}
    {--db-host=127.0.0.1 : MySQL: serverul}
    {--db-port=3306 : MySQL: portul}
    {--db-name= : MySQL: baza}
    {--db-user= : MySQL: utilizatorul (parola: CEMAINOU_DB_PASSWORD sau se cere)}
    {--generate-password : Generează parola adminului și o afișează o dată}
    {--feedback-to-rt : Trimite feedback-ul echipei și la RT (autorii cemainou)}')]
#[Description('Instalează cemainou din terminal: verifică cerințele, scrie .env, rulează migrările și creează primul admin')]
class CemainouInstall extends Command
{
    public function handle(): int
    {
        if (Installer::installed()) {
            $this->error('cemainou e deja instalat aici. Pentru o versiune nouă: php artisan cemainou:update.');

            return self::FAILURE;
        }
        if (! Installer::ready()) {
            foreach (Installer::requirements() as $r) {
                if (! $r['ok'] && $r['required']) {
                    $this->error('Lipsește: '.$r['label']);
                }
            }

            return self::FAILURE;
        }

        $mysql = $this->option('db') === 'mysql';
        $data = [
            'url' => $this->option('url') ?? $this->ask('Adresa echipei (https://...)'),
            'email' => $this->option('email') ?? $this->ask('Emailul primului admin'),
            'name' => $this->option('name') ?? $this->ask('Numele primului admin'),
            'locale' => $this->option('locale'),
            'timezone' => $this->option('timezone'),
            'database' => $this->option('db'),
            'db_host' => $mysql ? $this->option('db-host') : null,
            'db_port' => $mysql ? $this->option('db-port') : null,
            'db_name' => $mysql ? ($this->option('db-name') ?? $this->ask('MySQL: baza')) : null,
            'db_user' => $mysql ? ($this->option('db-user') ?? $this->ask('MySQL: utilizatorul')) : null,
            'db_password' => $mysql ? (getenv('CEMAINOU_DB_PASSWORD') ?: (string) $this->secret('MySQL: parola')) : null,
            'feedback_to_rt' => (bool) $this->option('feedback-to-rt'),
        ];
        $generated = $this->option('generate-password') ? Str::password(20, symbols: false) : null;
        $data['password'] = $generated ?? (getenv('CEMAINOU_ADMIN_PASSWORD') ?: $this->secret('Parola adminului (cel puțin 12 caractere)'));
        if (! $generated && ! getenv('CEMAINOU_ADMIN_PASSWORD') && $this->input->isInteractive() && $this->secret('Încă o dată parola') !== $data['password']) {
            $this->error('Parolele nu se potrivesc.');

            return self::FAILURE;
        }

        $check = Validator::make($data, Installer::rules() + ['password' => ['required', 'string', Password::min(12)]]);
        if ($check->fails()) {
            foreach ($check->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $admin = Installer::install($check->validated());
        } catch (Throwable $e) {
            $this->error('Instalarea s-a oprit: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Gata: cemainou e instalat la {$data['url']}. Intră cu {$admin->email}.");
        if ($generated) {
            $this->line("Parola adminului (afișată doar acum, schimb-o din profil): {$generated}");
        }

        return self::SUCCESS;
    }
}
