<?php

namespace App\Models;

use App\Mail\MonthlyReport;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\AccessGuard;
use App\Support\TeamMail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Raportul lunar al unui proiect pentru client (App\Support\ProjectReportData face conținutul, mereu din datele de acum). */
#[Fillable(['project_id', 'month', 'token', 'include_hours', 'intro', 'tz', 'created_by', 'sent_at', 'viewed_at'])]
class ProjectReport extends Model
{
    use StoresTimesInUtc;

    protected $attributes = ['include_hours' => false, 'intro' => null, 'tz' => 'Europe/Chisinau', 'created_by' => null, 'sent_at' => null, 'viewed_at' => null];

    public static function for(Project $project, string $month, User $by): self
    {
        return self::firstOrCreate(['project_id' => $project->id, 'month' => $month], ['token' => Str::random(40), 'tz' => $by->tz(), 'created_by' => $by->id]);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Trimis pe email oamenilor clientului (în numele lui $by); câți l-au primit. Fără email configurat sau fără oameni, 0 și rămâne netrimis. */
    public function send(User $by): int
    {
        $people = (TeamMail::ready() ? $this->project->clientPeople() : collect())->reject(fn (User $person) => AccessGuard::blocks($person, $this->project, 'report'))->values();
        foreach ($people as $person) {
            Mail::to($person)->send(new MonthlyReport($this, $by, $person));
        }
        if ($people->isNotEmpty()) {
            $this->forceFill(['sent_at' => now()])->save();
        }

        return $people->count();
    }

    public function url(): string
    {
        return route('report.show', $this->token);
    }

    protected function casts(): array
    {
        return ['include_hours' => 'boolean', 'sent_at' => 'datetime', 'viewed_at' => 'datetime'];
    }
}
