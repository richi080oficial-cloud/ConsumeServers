<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Extensions\ConsumeServers\Http\Controllers\Admin\ConsumeServersController;

/*
 * Rutas de administracion: /admin/extensions/consumeservers
 *
 * Se anida bajo /admin/extensions/ (igual que otras extensiones de este
 * mismo autor) para no colisionar con el resaltado del menu "Servers" de
 * algunos temas de admin, que activan esa seccion comprobando un prefijo
 * tipo admin/servers* / admin.servers* — "admin/consumeservers" no empieza
 * por esa cadena, pero mantener el mismo esquema evita sorpresas futuras.
 */
Route::middleware(['web', 'auth', AdminAuthenticate::class])
    ->prefix('admin/extensions/consumeservers')
    ->name('admin.extensions.consumeservers.')
    ->group(function () {
        Route::get('/', [ConsumeServersController::class, 'index'])->name('index');
        Route::post('/', [ConsumeServersController::class, 'store'])->name('store');
        Route::post('/check-now', [ConsumeServersController::class, 'checkNow'])->name('check-now');
        Route::put('/{limit}', [ConsumeServersController::class, 'update'])->name('update');
        Route::patch('/{limit}/toggle', [ConsumeServersController::class, 'toggle'])->name('toggle');
        Route::delete('/{limit}', [ConsumeServersController::class, 'destroy'])->name('destroy');

        // Apagado inmediato desde el ranking de "mas consumen" (no requiere
        // un limite configurado, es una accion manual del admin).
        Route::post('/servers/{server}/power', [ConsumeServersController::class, 'power'])->name('servers.power');
    });
