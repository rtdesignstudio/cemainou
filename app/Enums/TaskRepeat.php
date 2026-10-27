<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

/** Cât de des revine o sarcină: când e gata, următoarea se adaugă singură, cu termenul mutat (Task::repeatNext). */
enum TaskRepeat: string implements HasLabel
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Weekly => __('Every week'),
            self::Biweekly => __('Every 2 weeks'),
            self::Monthly => __('Every month'),
            self::Quarterly => __('Every 3 months'),
            self::Yearly => __('Every year'),
        };
    }

    /** Scurt, în rândul sarcinii („în fiecare lună"). */
    public function short(): string
    {
        return mb_strtolower($this->getLabel());
    }

    /** Termenul următor; la sfârșit de lună rămâne în luna următoare (31 ian → 28 feb). */
    public function next(CarbonInterface $from): CarbonInterface
    {
        $date = $from->copy();

        return match ($this) {
            self::Weekly => $date->addWeek(),
            self::Biweekly => $date->addWeeks(2),
            self::Monthly => $date->addMonthNoOverflow(),
            self::Quarterly => $date->addMonthsNoOverflow(3),
            self::Yearly => $date->addYearNoOverflow(),
        };
    }
}
