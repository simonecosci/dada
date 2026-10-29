<?php

use App\Deploy\Removal;
use App\Deploy\RemovalPlanner;
use App\Models\DeploymentHost;
use App\Models\DeploymentSnapshot;

function snapshotWith(DeploymentHost $dh, array $installations): DeploymentSnapshot
{
    return DeploymentSnapshot::create([
        'deployment_host_id' => $dh->id,
        'deploy_run_id' => null,
        'installations' => $installations,
        'host_variables' => [],
        'files' => [],
    ]);
}

it('plans nothing without a previous snapshot', function () {
    expect(RemovalPlanner::for(hostWithWordpress()))->toBe([]);
});

it('plans removals for slugs missing from the current installations', function () {
    $dh = hostWithWordpress();
    snapshotWith($dh, [
        ['slug' => 'wp', 'services' => ['wp']],
        ['slug' => 'old', 'services' => ['old-web', 'old-worker']],
    ]);

    $removals = RemovalPlanner::for($dh);

    expect($removals)->toHaveCount(1)
        ->and($removals[0])->toBeInstanceOf(Removal::class)
        ->and($removals[0]->slug)->toBe('old')
        ->and($removals[0]->services)->toBe(['old-web', 'old-worker'])
        ->and($removals[0]->files)->toBe(['.env.old', 'old.sh']);
});

it('uses only the latest snapshot', function () {
    $dh = hostWithWordpress();
    snapshotWith($dh, [['slug' => 'ancient', 'services' => ['a']]]);
    snapshotWith($dh, [['slug' => 'wp', 'services' => ['wp']]]);

    expect(RemovalPlanner::for($dh))->toBe([])
        ->and($dh->latestSnapshot->installations[0]['slug'])->toBe('wp');
});
