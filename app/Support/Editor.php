<?php

namespace App\Support;

use Closure;
use Filament\Forms\Components\MarkdownEditor;

/**
 * Editorul de text al aplicației, oriunde merge Markdown (comentarii, detaliile sarcinilor, feedback): editorul Markdown din Filament, cu o bară minimă
 * (bold, italic, tăiat, link, liste, cod, anulare), fără titluri, tabele sau fișiere (fișierele au butonul lor). Salvează Markdown, cum citesc și agenții.
 */
class Editor
{
    /**
     * $mentions (opțional): cine poate fi menționat aici (cine vede sarcina, update-ul, proiectul). Editorul propune numele când scrii „@"
     * (resources/views/filament/partials/mentions.blade.php); fără el, „@Nume" scris de mână merge oricum.
     *
     * @param  Closure|null  $mentions  întoarce oamenii (iterable<User>); primește parametrii obișnuiți Filament ($record...)
     */
    public static function markdown(string $name, ?Closure $mentions = null): MarkdownEditor
    {
        return MarkdownEditor::make($name)
            ->extraAlpineAttributes(fn (MarkdownEditor $component) => $mentions ? ['data-mentions' => e(json_encode(Mentions::candidates($component->evaluate($mentions), auth()->user()), JSON_UNESCAPED_UNICODE))] : [])   // evaluate: închiderea primește $record etc.; e(): Filament scrie atributele Alpine fără escape, iar numele vin de la oameni
            ->toolbarButtons([['bold', 'italic', 'strike', 'link'], ['bulletList', 'orderedList', 'codeBlock'], ['undo', 'redo']])   // fără citat: iconița lui e plină (se scrie > la început de rând)
            ->fileAttachments(false)
            ->minHeight('7rem')
            ->maxLength(5000);
    }
}
