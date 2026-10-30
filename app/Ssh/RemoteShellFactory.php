<?php

namespace App\Ssh;

use App\Models\Host;

interface RemoteShellFactory
{
    public function for(Host $host): RemoteShell;
}
