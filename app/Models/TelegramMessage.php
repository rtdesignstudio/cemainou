<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Un mesaj trimis de bot (în grup sau în privat) despre un update sau o sarcină: un răspuns la el devine comentariu acolo. */
#[Fillable(['chat_id', 'message_id', 'subject_type', 'subject_id'])]
class TelegramMessage extends Model
{
    use StoresTimesInUtc;

    public const UPDATED_AT = null;

    /** Update-ul sau sarcina despre care e mesajul; null dacă între timp a fost ștearsă. */
    public function subject(): Update|Task|null
    {
        return match ($this->subject_type) {
            'update' => Update::find($this->subject_id),
            'task' => Task::find($this->subject_id),
            default => null,
        };
    }
}
