# ConsumeServers

Extension para el panel de administración de [Pterodactyl](https://pterodactyl.io) que permite:

- Ver el consumo de recursos de cada servidor (CPU, RAM, tráfico de red y horas de actividad) desde una pantalla propia dentro del propio admin.
- Definir, por servidor, un umbral personalizado para cualquiera de esas métricas (instantáneo, diario o mensual).
- Apagar o suspender automáticamente el servidor cuando supera ese umbral, sin intervención manual.
- Instalarse, actualizarse y desinstalarse con un solo comando, sin dejar ningún rastro en el panel.

Sigue el mismo esquema de instalación que otras extensiones de este repositorio (p. ej. `ServerSplitter`): se copia a `app/Extensions/ConsumeServers` con el namespace `Pterodactyl\Extensions\ConsumeServers\*`, lo que significa que **el autoload PSR-4 que el panel ya trae de fábrica (`app/` → `Pterodactyl\`) la carga sola** — no hace falta tocar `composer.json`. Lo único que se parchea es:

1. `config/app.php` o `bootstrap/providers.php` — registra el Service Provider (una línea).
2. `resources/views/layouts/admin.blade.php` — añade el enlace "Consume Servers" al menú lateral (bloque delimitado por comentarios `consumeservers:begin/end`).

Ambos parches se revierten con exactitud durante la desinstalación. Además hay un middleware de respaldo (`AdminSidebarLink`) que reinyecta el enlace del menú en caliente si el parche del layout no pudo aplicarse (tema de terceros, actualización del panel, etc.), así que el enlace siempre aparece.

## Requisitos

- Pterodactyl Panel (Laravel) ya instalado, con acceso SSH root a la VPS.
- `git`, `php-cli` y `tar` disponibles en el servidor (se instalan solos si faltan).

## Instalación

```bash
bash <(curl -sSL https://raw.githubusercontent.com/<YOUR_GITHUB_USER>/ConsumeServers/main/install.sh) install
```

El instalador detecta la ruta del panel automáticamente (`/var/www/pterodactyl`, `/var/www/panel`, etc.). Si está en otro sitio:

```bash
sudo consumeservers install --panel=/ruta/al/panel
```

Al terminar aparece **"Consume Servers"** en el menú lateral de administración, en `/admin/extensions/consumeservers`.

## Comando `consumeservers`

Tras la primera instalación queda disponible un comando corto en `/usr/local/bin/consumeservers`:

```bash
sudo consumeservers install              # (re)instala
sudo consumeservers update               # descarga la última versión y la aplica
sudo consumeservers update --force       # fuerza la actualización aunque no haya cambios
sudo consumeservers uninstall            # desinstala (conserva las tablas)
sudo consumeservers uninstall --drop-data  # desinstala y borra también las tablas
sudo consumeservers status               # muestra el estado de la instalación
sudo consumeservers autoupdate-on        # programa actualización automática diaria (04:00)
sudo consumeservers autoupdate-off       # cancela la actualización automática
```

## Comandos `php artisan` (dentro del panel)

```bash
cd /ruta/al/panel
php artisan consumeservers:manage check   # fuerza una revisión manual de límites (se ejecuta solo cada minuto)
php artisan consumeservers:manage info    # estadísticas rápidas
php artisan consumeservers:manage purge   # borra las tablas de la extensión (usado por --drop-data)
```

## Desinstalar

```bash
sudo consumeservers uninstall --drop-data -y
```

Esto:
1. Borra las tablas (`consumeservers_limits`, `consumeservers_usage_logs`) si se pasó `--drop-data`.
2. Quita el bloque `consumeservers:begin/end` de `resources/views/layouts/admin.blade.php`.
3. Quita la línea del Service Provider de `config/app.php` (o `bootstrap/providers.php` en Laravel 11+).
4. Borra `app/Extensions/ConsumeServers` por completo.
5. Limpia cachés de config/vista/rutas.
6. Borra el código fuente (`/opt/consumeservers`), el comando (`/usr/local/bin/consumeservers`) y el cron de auto-actualización si estaba activo.

No queda ningún archivo, tabla, ruta ni referencia en el panel.

## Cómo funciona el chequeo automático

El `ConsumeServersServiceProvider` registra una tarea programada (`consumeservers:manage check`) directamente contra el `Schedule` de Laravel en tiempo de arranque — no toca `app/Console/Kernel.php`. Se apoya en el *scheduler* que Pterodactyl ya tiene configurado en el cron del sistema (`* * * * * php artisan schedule:run`), así que no requiere configurar nada adicional.

Cada minuto, por cada límite habilitado:
1. Pide a Wings las estadísticas actuales del servidor (CPU, RAM, red, uptime).
2. Si la métrica es acumulable (red/uptime), la compara contra la ventana del período (diario/mensual) guardada en `consumeservers_usage_logs`.
3. Si supera el umbral, ejecuta la acción configurada (`stop` o `suspend`) usando los mismos servicios internos que usa el panel.

## Antes de subir esto a tu propio GitHub

Sustituye `<YOUR_GITHUB_USER>` por tu usuario/repositorio real en:

- [install.sh](install.sh)
- [consumeservers.sh](consumeservers.sh)
- [scripts/addon-install.sh](scripts/addon-install.sh)
- [bin/consumeservers](bin/consumeservers)

Y prueba el flujo completo (`install` → `update` → `uninstall --drop-data`) en una VPS de staging antes de tocar producción, ya que el instalador modifica `config/app.php` (o `bootstrap/providers.php`) y el layout de admin del panel real.
