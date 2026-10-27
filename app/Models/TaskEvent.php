<?php

namespace App\Models;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un pas din istoria unei sarcini. */
#[Fillable(['task_id', 'user_id', 'type', 'from', 'to', 'update_id'])]
class TaskEvent extends Model
{
    use StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['user_id' => null, 'from' => null, 'to' => null, 'update_id' => null];

    public const UPDATED_AT = null;

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Update-ul care a mișcat sau a pomenit sarcina (nu „update()": numele e luat de Eloquent). */
    public function statusUpdate(): BelongsTo
    {
        return $this->belongsTo(Update::class, 'update_id');
    }

    /** Rândul din istorie, ex. „Ana Popescu created it for Ion Rusu" sau „In Ion Rusu's update: „Galeria merge" → Done · Claude Code". */
    /** Rândul din istoric; cu $self (pagina unui om) fără numele lui, care se înțelege: „a trecut-o la Blocată”, „a creat-o pentru sine”. */
    public function describe(?User $self = null): string
    {
        if ($self && $this->user_id === $self->id && ! $this->statusUpdate) {
            $to = $this->to === $self->name ? __('themselves') : $this->to;

            return match ($this->type) {
                TaskEventType::Created => $this->to ? __('created it for :to', ['to' => $to]) : __('created it, free'),
                TaskEventType::Assigned => $this->to ? __('gave it to :to', ['to' => $to]) : __('freed it'),
                TaskEventType::Taken => __('took it'),
                TaskEventType::Status => __('moved it to :status', ['status' => $this->to ? (TaskStatus::tryFrom($this->to)?->getLabel() ?? $this->to) : '']),
                TaskEventType::Mentioned => __('mentioned it'),
                TaskEventType::Edited => __('edited it'),
                TaskEventType::Files => $this->to ? __('replaced «:from» with «:to»', ['from' => $this->from, 'to' => $this->to]) : __('removed the file «:from»', ['from' => $this->from]),
            };
        }
        $name = $this->user?->name ?? __('Someone');
        $status = fn (?string $value) => $value ? (TaskStatus::tryFrom($value)?->getLabel() ?? $value) : '';

        if ($update = $this->statusUpdate) {
            $line = __('In :name\'s update', ['name' => $name]).': '.__('“:text”', ['text' => $update->summary]);
            if ($this->type === TaskEventType::Status) {
                $line .= ' → '.$status($this->to);
            }

            return $line.' · '.$update->source->getLabel();
        }

        return match ($this->type) {
            TaskEventType::Created => $this->to ? __(':name created it for :to', ['name' => $name, 'to' => $this->to]) : __(':name created it, free', ['name' => $name]),
            TaskEventType::Assigned => $this->to ? __(':name gave it to :to', ['name' => $name, 'to' => $this->to]) : __(':name freed it', ['name' => $name]),
            TaskEventType::Taken => __(':name took it', ['name' => $name]),
            TaskEventType::Status => __(':name moved it to :status', ['name' => $name, 'status' => $status($this->to)]),
            TaskEventType::Mentioned => __(':name mentioned it', ['name' => $name]),
            TaskEventType::Edited => __(':name edited it', ['name' => $name]),
            TaskEventType::Files => $this->to ? __(':name replaced «:from» with «:to»', ['name' => $name, 'from' => $this->from, 'to' => $this->to]) : __(':name removed the file «:from»', ['name' => $name, 'from' => $this->from]),
        };
    }

    protected function casts(): array
    {
        return ['type' => TaskEventType::class];
    }
}
