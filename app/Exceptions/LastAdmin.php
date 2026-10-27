<?php

namespace App\Exceptions;

use RuntimeException;

/** Ultimul admin activ nu poate fi retrogradat sau dezactivat: echipa ar rămâne fără administrator. */
class LastAdmin extends RuntimeException
{
    //
}
