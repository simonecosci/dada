<?php

namespace App\Ssh\Fake;

use App\Models\Host;
use App\Ssh\RemoteShellFactory;

class FakeRemoteShellFactory implements RemoteShellFactory
{
    /** @var array<int, FakeRemoteShell> */
    private array $shells = [];

    public function shellFor(Host $host): FakeRemoteShell
    {
        return $this->shells[$host->id] ??= new FakeRemoteShell;
    }

    public function for(Host $host): FakeRemoteShell
    {
        return $this->shellFor($host);
    }
}
