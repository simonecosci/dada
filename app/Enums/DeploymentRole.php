<?php

namespace App\Enums;

enum DeploymentRole: string
{
    case Operator = 'operator';
    case Viewer = 'viewer';
}
