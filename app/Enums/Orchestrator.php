<?php

namespace App\Enums;

enum Orchestrator: string
{
    case Compose = 'compose';
    case Swarm = 'swarm';
}
