<?php

namespace App\Exceptions;

use RuntimeException;

/** Ștergerea ar pierde istoric (update-uri, ore, sarcini): omul se dezactivează, proiectul se arhivează, clientul rămâne. */
class KeepsHistory extends RuntimeException
{
    //
}
