<?php

namespace Tests\Feature;

use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\User;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

/** Iconițele din interfață sunt cu linie, nu pline; și mesajele listelor goale sună corect în română. */
class LineIconsTest extends TestCase
{
    use RefreshDatabase;

    /** Locuri pe care nu le folosim și care n-au o variantă cu linie (editorul de imagini, widget-ul Filament, „x"-ul desenat din CSS). */
    private const SKIPPED = [
        'forms::components.file-upload.editor.actions.drag-move',
        'forms::components.file-upload.editor.actions.flip-horizontal',
        'forms::components.file-upload.editor.actions.flip-vertical',
        'panels::widgets.filament-info.open-github-button',
        'badge.delete-button',
    ];

    public function test_every_filament_icon_alias_resolves_to_a_line_icon(): void
    {
        $solid = [];
        foreach (glob(base_path('vendor/filament/*/src/View/*IconAlias.php')) as $file) {
            require_once $file;
            preg_match('/namespace ([^;]+);/', file_get_contents($file), $ns);
            $class = $ns[1].'\\'.basename($file, '.php');

            foreach ((new ReflectionClass($class))->getConstants() as $name => $alias) {
                $icon = FilamentIcon::resolve($alias);
                if (! in_array($alias, self::SKIPPED, true) && ! ($icon instanceof Heroicon && str_starts_with($icon->value, 'o-'))) {
                    $solid[] = basename($file, '.php')."::{$name}";
                }
            }
        }

        $this->assertSame([], $solid, 'Iconițe rămase pline (adaugă-le în App\Support\LineIcons): '.implode(', ', $solid));
    }

    public function test_an_empty_task_list_reads_well_in_romanian(): void
    {
        $this->actingAs(User::factory()->create(['locale' => 'ro']));
        app()->setLocale('ro');

        Livewire::test(ListTasks::class)->assertSee('Nicio sarcină')->assertDontSee('Niciun sarcini');
    }
}
