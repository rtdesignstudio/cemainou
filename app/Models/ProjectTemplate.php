<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Un șablon de proiect: sarcinile standard ale unui fel de proiect (website, branding, campanie, rebranding, o lună de contabilitate), puse libere pe un proiect nou,
 * cu termenul la câte zile de la pornire, dacă are. Se fac din Proiecte → Șabloane sau din „Salvează ca șablon" pe un proiect.
 */
#[Fillable(['name', 'description', 'tasks', 'created_by'])]
class ProjectTemplate extends Model
{
    use StoresTimesInUtc;

    protected $attributes = ['description' => null, 'tasks' => '[]', 'created_by' => null];

    /** Pune sarcinile pe proiect, libere (oricine le ia), în ordinea din șablon. @return int câte */
    public function applyTo(Project $project, User $by): int
    {
        $today = now($by->tz())->startOfDay();

        return DB::transaction(function () use ($project, $by, $today) {
            $count = 0;
            foreach ($this->tasks ?? [] as $task) {
                if (blank($task['title'] ?? null)) {
                    continue;
                }
                $days = filled($task['due_in_days'] ?? null) ? (int) $task['due_in_days'] : null;
                Task::add($project, $by, $task['title'], $task['notes'] ?? null, due: $days !== null ? $today->copy()->addDays($days)->toDateString() : null);
                $count++;
            }

            return $count;
        });
    }

    /** Din sarcinile unui proiect (fără idei și renunțate), în ordinea în care au fost puse; termenul, cât era de la pornirea proiectului. */
    public static function fromProject(Project $project, User $by, string $name): self
    {
        $start = $project->created_at->copy()->startOfDay();
        $tasks = $project->tasks()->whereNotIn('status', [TaskStatus::Idea->value, TaskStatus::Dropped->value])->orderBy('id')->get()
            ->map(fn (Task $task) => ['title' => $task->title, 'notes' => $task->notes,
                'due_in_days' => $task->due_on ? max(0, (int) $start->diffInDays($task->due_on->copy()->startOfDay(), false)) : null])->values()->all();

        return self::create(['name' => $name, 'description' => __('Made from :project.', ['project' => $project->name]), 'tasks' => $tasks, 'created_by' => $by->id]);
    }

    /**
     * Șabloanele de pornire, în limba celui care le adaugă (la instalare, sau din Șabloane): website, branding, campanie, rebranding și o lună
     * de contabilitate, ca echipele care nu fac site-uri să aibă și ele de unde porni. Doar cele care lipsesc (după nume); se schimbă apoi după
     * cum lucrează echipa. @return int câte s-au adăugat
     */
    public static function addStarters(User $by): int
    {
        $added = 0;
        foreach (self::missingStarters() as $name => $tasks) {
            self::create(['name' => $name, 'created_by' => $by->id,
                'tasks' => array_map(fn (array $t) => ['title' => $t[0], 'notes' => null, 'due_in_days' => $t[1]], $tasks)]);
            $added++;
        }

        return $added;
    }

    /** Șabloanele de pornire pe care echipa nu le are încă (după nume, în limba de acum). @return array<string, list<array{0: string, 1: int}>> */
    public static function missingStarters(): array
    {
        $sets = [
            __('Website') => [[__('Brief and goals'), 0], [__('Sitemap and content plan'), 5], [__('Wireframes'), 10], [__('Visual design'), 20], [__('Development'), 35], [__('Content entry'), 40], [__('Testing on phones and browsers'), 45], [__('Launch and redirects'), 50]],
            __('Branding') => [[__('Brief and research'), 0], [__('Moodboard'), 5], [__('Logo concepts'), 14], [__('Presentation to the client'), 18], [__('Refinements'), 25], [__('Brand book'), 32], [__('File delivery'), 35]],
            __('Campaign') => [[__('Brief and audience'), 0], [__('Concept and key message'), 4], [__('Content calendar'), 7], [__('Visuals and copy'), 12], [__('Client approval'), 14], [__('Publishing'), 15], [__('Results report'), 45]],
            __('Rebranding') => [[__('Audit of the current brand'), 0], [__('Logo, three options'), 10], [__('Client feedback on the logo'), 14], [__('Brand book'), 28], [__('Website design'), 40], [__('Website development'), 60], [__('Launch of the new brand'), 65]],
            __('Accounting month') => [[__('Collect the month\'s documents'), 3], [__('Book invoices and receipts'), 8], [__('Payroll'), 10], [__('VAT return'), 20], [__('Monthly report to the client'), 25]],
        ];
        $have = self::pluck('name')->map(fn ($name) => mb_strtolower($name))->all();

        return array_filter($sets, fn ($name) => ! in_array(mb_strtolower($name), $have, true), ARRAY_FILTER_USE_KEY);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['tasks' => 'array'];
    }
}
