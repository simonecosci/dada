<?php

use App\Models\Deployment;
use App\Models\DeployRun;
use App\Models\ProjectVersion;
use App\Models\SecretReveal;
use App\Models\User;
use App\Models\Variable;
use App\Users\UserDeletionGuard;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function deletionAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('keeps runs and reveals of a deleted user with the name snapshot', function () {
    $user = User::factory()->create(['name' => 'Mario Rossi', 'is_maintainer' => true]);
    $run = DeployRun::factory()->create(['user_id' => $user->id]);
    $var = Variable::factory()->secret()->for(ProjectVersion::factory(), 'owner')->create(['default' => 'shh']);
    $this->actingAs($user)->postJson("/variables/{$var->id}/reveal")->assertOk();
    $reveal = SecretReveal::sole();

    expect($run->fresh()->user_name)->toBe('Mario Rossi')
        ->and($reveal->user_name)->toBe('Mario Rossi');

    $this->actingAs(deletionAdmin())->delete("/admin/users/{$user->id}")->assertRedirect('/admin/users');

    expect(User::find($user->id))->toBeNull()
        ->and($run->fresh())->not->toBeNull()->user_id->toBeNull()->user_name->toBe('Mario Rossi')
        ->and($reveal->fresh())->not->toBeNull()->user_id->toBeNull()->user_name->toBe('Mario Rossi');
});

it('shows the snapshot name on the run page after the user is gone', function () {
    $user = User::factory()->create(['name' => 'Mario Rossi']);
    $run = DeployRun::factory()->create(['user_id' => $user->id]);
    $user->delete();
    $admin = deletionAdmin();

    $this->actingAs($admin)->get("/runs/{$run->id}")
        ->assertInertia(fn ($page) => $page->where('run.user', 'Mario Rossi'));
});

it('snapshots the user name on runs created by the controllers', function () {
    Queue::fake();
    $dh = hostWithWordpress();
    $op = operatorOf($dh->deployment);
    $op->update(['name' => 'Op Uno']);

    $this->actingAs($op)->post("/deployments/{$dh->deployment_id}/deploy");

    expect(DeployRun::sole()->user_name)->toBe('Op Uno');
});

it('refuses to delete the last operator of a deployment', function () {
    $d = Deployment::factory()->create(['name' => 'Cliente X']);
    $op = operatorOf($d);

    $this->actingAs(deletionAdmin())->delete("/admin/users/{$op->id}")
        ->assertSessionHasErrors(['user' => 'L\'utente è l\'unico operatore del deployment "Cliente X": assegna prima un altro operatore.']);
    expect(User::find($op->id))->not->toBeNull();

    // Con un secondo operatore la cancellazione è permessa.
    operatorOf($d);
    $this->actingAs(deletionAdmin())->delete("/admin/users/{$op->id}")->assertRedirect('/admin/users');
    expect(User::find($op->id))->toBeNull();
});

it('refuses to delete the last admin', function () {
    $only = deletionAdmin();

    expect(fn () => UserDeletionGuard::check($only))
        ->toThrow(ValidationException::class, 'Non puoi cancellare l\'ultimo amministratore.');

    deletionAdmin();
    UserDeletionGuard::check($only);
    expect(true)->toBeTrue();
});

it('no longer lets users delete their own account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])
        ->assertStatus(405);
    expect(User::find($user->id))->not->toBeNull()
        ->and(Route::has('profile.destroy'))->toBeFalse();
});
