<?php

namespace App\Exceptions;

use App\Models\Document;
use RuntimeException;

/** Cineva a salvat documentul după ce l-ai deschis: salvarea ta nu-i suprascrie versiunea; o combini și salvezi din nou. */
class DocumentConflict extends RuntimeException
{
    public function __construct(public readonly Document $current)
    {
        parent::__construct("{$current->title} was saved meanwhile (version {$current->version}).");
    }
}
