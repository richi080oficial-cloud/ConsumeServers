<?php

namespace Pterodactyl\Extensions\ConsumeServers\Models;

use Illuminate\Database\Eloquent\Model;
use Pterodactyl\Models\Server;

class ConsumeServerLimit extends Model
{
    protected $table = 'consumeservers_limits';

    protected $fillable = [
        'server_id',
        'metric',
        'period',
        'threshold_value',
        'action',
        'notify_admin',
        'enabled',
        'triggered_at',
    ];

    protected $casts = [
        'notify_admin' => 'boolean',
        'enabled' => 'boolean',
        'triggered_at' => 'datetime',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
