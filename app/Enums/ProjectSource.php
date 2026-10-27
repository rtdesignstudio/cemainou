<?php

namespace App\Enums;

/** De unde știe o sesiune de agent la ce proiect lucrează. */
enum ProjectSource: string
{
    case Person = 'person';          // omul a spus (use_project)
    case Folder = 'folder';          // moștenit: alegerea omului de data trecută din același folder
    case File = 'file';              // fișierul .cemainou (sau vechiul .whatsupai) din folder
    case Repo = 'repo';              // repo-ul git legat de proiect
}
