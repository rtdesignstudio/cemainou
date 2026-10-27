<?php

namespace App\Models;

use App\Enums\Source;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\Inbox;
use App\Support\TaskNotices;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un comentariu pe o sarcină: o clarificare, o întrebare, un răspuns (și de la client, pe proiectele vizibile lui). Markdown. */
#[Fillable(['task_id', 'user_id', 'body', 'source'])]
class TaskComment extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['user_id' => null, 'source' => 'web'];

    /** Un comentariu nou: emailul la cine trebuie să afle (TaskNotices), după răspuns. */
    protected static function booted(): void
    {
        static::created(function (TaskComment $comment) {
            TaskNotices::commented($comment);
            Inbox::commented($comment);                                  // clopoțelul: cei de pe sarcină
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['source' => Source::class];
    }
}
