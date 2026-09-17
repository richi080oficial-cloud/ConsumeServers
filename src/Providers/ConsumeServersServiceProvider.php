<?php

namespace Pterodactyl\Extensions\ConsumeServers\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Extensions\ConsumeServers\Console\Commands\ConsumeServersCommand;
use Pterodactyl\Extensions\ConsumeServers\Http\Middleware\AdminSidebarLink;

/**
 * Punto de entrada de la extension. Todo (vistas, rutas y migraciones) se
 * carga desde este directorio: no hace falta publicar nada en el panel.
 */
class ConsumeServersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // IMPORTANTE: las rutas se cargan aqui, en register(), y no en boot().
        //
        // El panel de cliente de Pterodactyl es una SPA de React servida por
        // una ruta "catch-all" registrada en el boot() del RouteServiceProvider
        // del propio nucleo. El register() de TODOS los providers se ejecuta
        // antes que el boot() de CUALQUIERA, sin importar el orden en el
        // array "providers", asi que cargando aqui nuestras rutas quedamos
        // registrados antes que el catch-all pase lo que pase.
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'consumeservers');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ConsumeServersCommand::class]);
        }

        // Red de seguridad del parche Blade (tools/patch-panel.php): garantiza
        // el enlace del sidebar aunque el layout haya sido sobrescrito por un
        // tema o una actualizacion del panel. Ver AdminSidebarLink.
        $this->app->make('router')->pushMiddlewareToGroup('web', AdminSidebarLink::class);

        // Registra el chequeo periodico sin tocar app/Console/Kernel.php. Se
        // apoya en el cron "php artisan schedule:run" que Pterodactyl ya
        // trae configurado de fabrica (si ese cron no esta puesto, ESTE
        // chequeo automatico nunca corre — usa el boton "Revisar limites
        // ahora" del panel, o `php artisan consumeservers:manage check` a
        // mano, para comprobarlo sin depender del cron).
        //
        // Sin ->runInBackground(): si algo falla, el error queda en
        // storage/logs/consumeservers.log en vez de perderse en un proceso
        // en segundo plano sin salida capturada.
        $this->app->booted(function () {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('consumeservers:manage check')
                ->everyMinute()
                ->appendOutputTo(storage_path('logs/consumeservers.log'));
        });
    }
}
