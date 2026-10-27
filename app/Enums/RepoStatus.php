<?php

namespace App\Enums;

/** Un repo în plus al unui proiect: legat (lucrul din el ajunge pe proiect), propus (cineva a lucrat din el; liderul decide) sau respins (nu se mai propune). */
enum RepoStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Dismissed = 'dismissed';
}
