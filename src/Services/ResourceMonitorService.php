<?php

namespace Pterodactyl\Extensions\ConsumeServers\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Servers\SuspensionService;
use Pterodactyl\Extensions\ConsumeServers\Models\ConsumeServerLimit;
use Pterodactyl\Extensions\ConsumeServers\Models\ConsumeServerUsageLog;

class ResourceMonitorService
{
    // Cuanto se guarda en cache la respuesta del ping de Minecraft. Va aparte
    // del cache del ranking de consumo: no tiene sentido golpear el puerto
    // del juego de cada servidor cada 3-4 segundos solo porque la pantalla
    // se auto-refresca a esa velocidad.
    protected const MINECRAFT_CACHE_TTL = 15;

    public function __construct(
        protected DaemonServerRepository $daemonServerRepository,
        protected SuspensionService $suspensionService,
        protected MinecraftPingService $minecraftPing,
    ) {
    }

    /**
     * Evaluate every enabled limit and apply the configured action when a
     * server has gone over its threshold.
     *
     * Devuelve un informe por limite (no solo escribe en el log) para poder
     * mostrarlo en el panel cuando se lanza manualmente con el boton
     * "Revisar limites ahora" — asi se puede diagnosticar sin depender de
     * si el cron del panel esta corriendo `schedule:run` o no.
     *
     * @return array<int, array{limit: ConsumeServerLimit, server: string, current: int, threshold: int, triggered: bool, error: string|null}>
     */
    public function checkAll(): array
    {
        $report = [];

        ConsumeServerLimit::query()
            ->where('enabled', true)
            ->with('server')
            ->chunk(50, function ($limits) use (&$report) {
                foreach ($limits as $limit) {
                    if (!$limit->server) {
                        $report[] = $this->reportRow($limit, null, null, false, 'El servidor ya no existe.');

                        continue;
                    }

                    if ($limit->server->isSuspended()) {
                        $report[] = $this->reportRow($limit, $limit->server->name, null, false, 'El servidor ya esta suspendido, se omite.');

                        continue;
                    }

                    try {
                        [$currentValue, $triggered] = $this->evaluate($limit);
                        $report[] = $this->reportRow($limit, $limit->server->name, $currentValue, $triggered, null);
                    } catch (\Throwable $exception) {
                        Log::error("[ConsumeServers] Could not evaluate limit #{$limit->id}: {$exception->getMessage()}", [
                            'exception' => $exception,
                        ]);
                        $report[] = $this->reportRow($limit, $limit->server->name, null, false, $exception->getMessage());
                    }
                }
            });

        return $report;
    }

    protected function reportRow(ConsumeServerLimit $limit, ?string $serverName, ?int $currentValue, bool $triggered, ?string $error): array
    {
        return [
            'limit' => $limit,
            'server' => $serverName ?? "#{$limit->server_id}",
            'current' => $currentValue,
            'threshold' => $limit->threshold_value,
            'triggered' => $triggered,
            'error' => $error,
        ];
    }

    /**
     * @return array{0: int, 1: bool} [valor actual, si se disparo la accion]
     */
    protected function evaluate(ConsumeServerLimit $limit): array
    {
        $server = $limit->server;
        $utilization = $this->readUtilization($server);

        $currentValue = match ($limit->metric) {
            'cpu', 'memory' => $this->extractValue($limit->metric, $utilization),
            'network' => $this->accumulate($limit, $this->extractValue('network', $utilization)),
            'uptime' => $this->accumulate($limit, $this->extractValue('uptime', $utilization)),
            default => 0,
        };

        $triggered = $currentValue >= $limit->threshold_value;

        if ($triggered) {
            $this->applyAction($limit, $currentValue);
        }

        return [$currentValue, $triggered];
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
        $query = Server::query()->with(['node', 'user', 'egg', 'allocation']);

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

            $value = $this->extractValue($metric, $utilization);

            // El CPU que reporta Wings es "absoluto": un proceso usando 3
            // nucleos completos marca ~300%, no un error. Se calcula tambien
            // el porcentaje relativo al limite de CPU asignado al servidor
            // (columna "cpu", 0 = ilimitado) para poder mostrarlo de forma
            // que tenga sentido en pantalla en vez de un numero suelto.
            $cpuLimit = (int) ($server->cpu ?? 0);
            $cpuRelative = $metric === 'cpu' && $cpuLimit > 0
                ? (int) round(($value / $cpuLimit) * 100)
                : null;

            $results[] = [
                'server' => $server,
                'value' => $value,
                'cpu_limit' => $cpuLimit,
                'cpu_relative' => $cpuRelative,
                'is_minecraft' => $this->isMinecraftServer($server),
                'players' => $this->isMinecraftServer($server) ? $this->minecraftPlayers($server) : null,
            ];
        }

        usort($results, fn ($a, $b) => $b['value'] <=> $a['value']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Detecta si un servidor es de Minecraft (Java Edition) mirando el egg,
     * el nest al que pertenece y la imagen de Docker — sin depender de que
     * el admin lo marque a mano. Cubre eggs comunes: Vanilla, Paper, Spigot,
     * Purpur, Forge, Fabric, etc.
     */
    public function isMinecraftServer(Server $server): bool
    {
        $haystacks = [
            $server->egg->name ?? '',
            $server->egg->nest->name ?? '',
            $server->image ?? '',
        ];

        foreach ($haystacks as $text) {
            if (stripos((string) $text, 'minecraft') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jugadores conectados ahora mismo, leidos con el protocolo "Server
     * List Ping" del propio juego (el mismo que usa el cliente para
     * mostrar el servidor en la lista de multijugador). Devuelve null si
     * el servidor esta apagado, no tiene el puerto de consulta abierto o
     * no respondio a tiempo — no significa necesariamente un error.
     *
     * @return array{online: bool, players_online: int, players_max: int}|null
     */
    public function minecraftPlayers(Server $server): ?array
    {
        $allocation = $server->allocation;

        if (!$allocation || !$allocation->ip || !$allocation->port) {
            return null;
        }

        $host = $allocation->ip_alias ?: $allocation->ip;
        $port = (int) $allocation->port;

        return Cache::remember(
            "consumeservers.mcping.{$server->id}",
            self::MINECRAFT_CACHE_TTL,
            fn () => $this->minecraftPing->ping($host, $port)
        );
    }

    /**
     * Apaga un servidor al instante desde la interfaz (boton "Apagar" del
     * ranking), sin pasar por un limite configurado.
     */
    public function powerOff(Server $server): void
    {
        $this->daemonServerRepository->setServer($server)->setPowerState('stop');
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
