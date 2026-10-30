<?php

namespace App\Models;

use App\Enums\CredentialType;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['secret', 'passphrase'];

    public function hosts(): HasMany
    {
        return $this->hasMany(Host::class);
    }

    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'secret' => 'encrypted',
            'passphrase' => 'encrypted',
        ];
    }
}
