<?php

namespace App\Enums;

/** Abilities of the API personal access tokens. A `deploy` token can also read. */
enum TokenAbility: string
{
    case Read = 'read';
    case Deploy = 'deploy';
}
