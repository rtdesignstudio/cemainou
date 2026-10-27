<?php

namespace App\Enums;

/** Un atașament de proiect: un fișier păstrat în data/files sau un link extern (Figma, Drive, Behance). */
enum AttachmentKind: string
{
    case File = 'file';
    case Link = 'link';
}
