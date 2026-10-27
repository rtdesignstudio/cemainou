<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un email despre o sarcină care așteaptă să plece (App\Support\Outbox): cui, despre ce, când e gata de trimis. */
#[Fillable(['user_id', 'task_id', 'actor_id', 'comment_id', 'kind', 'url', 'due_at', 'sent_at', 'result'])]
class MailOutbox extends Model
{
    use StoresTimesInUtc;

    protected $table = 'mail_outbox';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'comment_id');
    }

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
