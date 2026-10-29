<?php

namespace App\Enums;

enum RunType: string
{
    case Deploy = 'deploy';
    case Restart = 'restart';
}
