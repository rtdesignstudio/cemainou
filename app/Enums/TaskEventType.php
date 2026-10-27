<?php

namespace App\Enums;

/** Ce s-a întâmplat cu o sarcină: din aceste evenimente se face istoria de pe pagina ei. */
enum TaskEventType: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case Taken = 'taken';
    case Status = 'status';
    case Mentioned = 'mentioned';
    case Edited = 'edited';
    case Files = 'files';                                       // un fișier șters (from) sau înlocuit (from → to)
}
