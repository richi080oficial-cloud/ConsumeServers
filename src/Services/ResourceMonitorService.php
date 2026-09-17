<?php

namespace Pterodactyl\Extensions\ConsumeServers\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Servers\SuspensionService;
use Pterodactyl\Extensions\ConsumeServers\Models\ConsumeServerLimit;
use Pterodactyl\Extensions\ConsumeServers\Models\ConsumeServerUsageLog;

class ResourceMonitorService
{
    public function __construct(
        protected DaemonServerRepository $daemonServerRepository,
        protected SuspensionService $suspensionService,
    ) {
    }

    /**
     * Evaluate every enabled limit and apply the configured action when a
     * server has gone over its threshold.
     */
    public function checkAll(): void
    {
        ConsumeServerLimit::query()
            ->where('enabled', true)
            ->with('server')
            ->chunk(50, function ($limits) {
                foreach ($limits as $limit) {
                    if (!$limit->server || $limit->server->isSuspended()) {
                        continue;
                    }

                    try {
                        $this->evaluate($limit);
                    } catch (\Throwable $exception) {
                        Log::warning("[ConsumeServers] Could not evaluate limit #{$limit->id}: {$exception->getMessage()}");
                    }
                }
            });
    }

    protected function evaluate(ConsumeServerLimit $limit): void
    {
        $server = $limit->server;
        $utilization = $this->readUtilization($server);

        $currentValue = match ($limit->metric) {
            'cpu', 'memory' => $this->extractValue($limit->metric, $utilization),
            'network' => $this->accumulate($limit, $this->extractValue('network', $utilization)),
            'uptime' => $this->accumulate($limit, $this->extractValue('uptime', $utilization)),
            default => 0,
        };

        if ($currentValue >= $limit->threshold_value) {
            $this->applyAction($limit, $currentValue);
        }
    }

    /**
     * Ranking de los servidores que mas consumen ahora mismo para una
     * metrica dada (uso instantaneo reportado por Wings, no la ventana
     * acumulada que usan los limites de red/uptime).
     *
     * @return array<int, array{server: Server, value: int}>
     */
    public function topConsumers(string $metric, ?int $nodeId = null, int $limit = 25): array
    {
        $query = Server::query()->with('node');

        if ($nodeId) {
            $query->where('node_id', $nodeId);
        }

        $results = [];

        foreach ($query->cursor() as $server) {
            try {
                $utilization = $this->readUtilization($server);
            } catch (\Throwable $exception) {
                continue;
            }

            $results[] = [
                'server' => $server,
                'value' => $this->extractValue($metric, $utilization),
            ];
        }

        usort($results, fn ($a, $b) => $b['value'] <=> $a['value']);

        return array_slice($results, 0, $limit);
    }

    protected function readUtilization(Server $server): array
    {
        $stats = $this->daemonServerRepository->setServer($server)->getDetails();

        return $stats['utilization'] ?? [];
    }

    protected function extractValue(string $metric, array $utilization): int
    {
        return match ($metric) {
            'cpu' => (int) round($utilization['cpu_absolute'] ?? 0),
            'memory' => (int) round(($utilization['memory_bytes'] ?? 0) / 1024 / 1024),
            'network' => $this->networkBytesToMb($utilization),
            'uptime' => (int) round(($utilization['uptime'] ?? 0) / 1000 / 60),
            default => 0,
        };
    }

    protected function networkBytesToMb(array $utilization): int
    {
        $rx = $utilization['network']['rx_bytes'] ?? 0;
        $tx = $utilization['network']['tx_bytes'] ?? 0;

        return (int) round(($rx + $tx) / 1024 / 1024);
    }

    /**
     * For cumulative metrics (network / uptime) we track the running total
     * inside the current period window (daily or monthly) instead of the
     * instantaneous value reported by Wings.
     */
    protected function accumulate(ConsumeServerLimit $limit, int $sampleValue): int
    {
        $periodStart = $limit->period === 'monthly'
            ? Carbon::now()->startOfMonth()
            : Carbon::now()->startOfDay();

        $log = ConsumeServerUsageLog::firstOrCreate(
            [
                'server_id' => $limit->server_id,
                'metric' => $limit->metric,
                'period' => $limit->period,
                'period_start' => $periodStart,
            ],
            ['accumulated_value' => 0]
        );

        if ($sampleValue > $log->accumulated_value) {
            $log->update(['accumulated_value' => $sampleValue]);
        }

        return (int) $log->accumulated_value;
    }

    protected function applyAction(ConsumeServerLimit $limit, int $currentValue): void
    {
        $server = $limit->server;

        if ($limit->action === 'suspend') {
            $this->suspensionService->toggle($server, 'suspend');
        } else {
            $this->daemonServerRepository->setServer($server)->setPowerState('stop');
        }

        $limit->update(['triggered_at' => Carbon::now()]);

        Log::info("[ConsumeServers] Server #{$server->id} ({$server->name}) exceeded {$limit->metric} limit ({$currentValue}/{$limit->threshold_value}) — action: {$limit->action}");
    }
}
