<?php

namespace App\Domain\Identity\Enums;

/** Basis of a representation. New kinds need no migration (text column). */
enum RepresentationKind: string
{
    /** Parent or legal guardian of a minor or incapacitated person. */
    case Guardian = 'guardian';
    /** Authorization given by the represented person (power of attorney, assistant). */
    case Authorized = 'authorized';
}
