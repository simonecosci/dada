<?php

namespace App\Enums;

enum VariableScope: string
{
    case Container = 'container';
    case Compose = 'compose';
    case HostGlobal = 'host-global';
}
