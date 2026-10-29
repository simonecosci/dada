<?php

use App\Catalog\VersionCloner;
use App\Enums\VersionStatus;
use App\Models\ProjectVersion;
use App\Variables\VariableSync;

it('clones a version with its variables as draft', function () {
    $src = ProjectVersion::factory()->published()->create(['version' => '1.0']);
    VariableSync::sync($src, [['key' => 'WP_VERSION', 'scope' => 'compose', 'default' => '6.6']]);
    $new = VersionCloner::clone($src, '1.1');
    expect($new->status)->toBe(VersionStatus::Draft)->and($new->variables()->first()->default)->toBe('6.6');
    expect($new->compose)->toBe($src->compose)->and($new->project_id)->toBe($src->project_id);
});
