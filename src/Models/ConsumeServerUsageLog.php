<?php

namespace Pterodactyl\Extensions\ConsumeServers\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumeServerUsageLog extends Model
{
    protected $table = 'consumeservers_usage_logs';

    protected $fillable = [
        'server_id',
        'metric',
        'period',
        'period_start',
        'accumulated_value',
    ];

    protected $casts = [
        'period_start' => 'datetime',
    ];
}
