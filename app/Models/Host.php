<?php

namespace App\Models;

use Database\Factories\HostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Host extends Model
{
    /** @use HasFactory<HostFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'reachable' => 'boolean',
            'facts' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function jumpHost(): BelongsTo
    {
        return $this->belongsTo(self::class, 'jump_host_id');
    }
}
