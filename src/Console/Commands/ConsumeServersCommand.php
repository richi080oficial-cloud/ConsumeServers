<?php

namespace Pterodactyl\Extensions\ConsumeServers\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\ConsumeServers\Models\ConsumeServerLimit;
use Pterodactyl\Extensions\ConsumeServers\Services\ResourceMonitorService;

class ConsumeServersCommand extends Command
{
    protected $signature = 'consumeservers:manage
                            {action=check : check | info | purge}
                            {--force : No pedir confirmacion (necesario para purge)}';

    protected $description = 'Utilidades internas de ConsumeServers (usadas por el CLI consumeservers).';

    protected const TABLES = [
        'consumeservers_usage_logs',
        'consumeservers_limits',
    ];

    public function handle(ResourceMonitorService $monitor): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'check' => $this->check($monitor),
            'info' => $this->infoAction(),
            'purge' => $this->purge(),
            default => $this->unknown($action),
        };
    }

    protected function check(ResourceMonitorService $monitor): int
    {
        if (!Schema::hasTable('consumeservers_limits')) {
            $this->error('Las tablas de ConsumeServers no existen todavia.');

            return self::FAILURE;
        }

        $monitor->checkAll();

        return self::SUCCESS;
    }

    protected function infoAction(): int
    {
        if (!Schema::hasTable('consumeservers_limits')) {
            $this->error('Las tablas de ConsumeServers no existen. Ejecuta: consumeservers install');

            return self::FAILURE;
        }

        $this->table(['Metrica', 'Total'], [
            ['Limites configurados', (string) ConsumeServerLimit::query()->count()],
            ['Limites activos', (string) ConsumeServerLimit::query()->where('enabled', true)->count()],
            ['Limites disparados alguna vez', (string) ConsumeServerLimit::query()->whereNotNull('triggered_at')->count()],
        ]);

        return self::SUCCESS;
    }

    protected function purge(): int
    {
        if (!$this->option('force') && !$this->confirm('Se eliminaran TODAS las tablas de ConsumeServers. Los servidores NO se borran. Continuar?', false)) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
                $this->line("Tabla eliminada: $table");
            }
        }

        $this->info('Datos de ConsumeServers eliminados.');

        return self::SUCCESS;
    }

    protected function unknown(string $action): int
    {
        $this->error("Accion desconocida: $action (usa check, info o purge)");

        return self::FAILURE;
    }
}
