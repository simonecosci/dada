<?php

use App\Models\ProjectVersion;
use App\Models\SecretReveal;
use App\Models\User;
use App\Models\Variable;

it('lets a maintainer reveal a catalog secret and audits it', function () {
    $m = User::factory()->create(['is_maintainer' => true]);
    $var = Variable::factory()->secret()->for(ProjectVersion::factory(), 'owner')->create(['default' => 'shh']);
    $this->actingAs($m)->postJson("/variables/{$var->id}/reveal")->assertJson(['value' => 'shh']);
    expect(SecretReveal::where('variable_id', $var->id)->where('user_id', $m->id)->exists())->toBeTrue();
});

it('forbids reveal to non maintainers', function () {
    $var = Variable::factory()->secret()->for(ProjectVersion::factory(), 'owner')->create();
    $this->actingAs(User::factory()->create())->postJson("/variables/{$var->id}/reveal")->assertForbidden();
    expect(SecretReveal::count())->toBe(0);
});
