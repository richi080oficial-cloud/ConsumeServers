<?php

namespace Pterodactyl\Extensions\ConsumeServers\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Extensions\ConsumeServers\Models\ConsumeServerLimit;
use Pterodactyl\Extensions\ConsumeServers\Services\ResourceMonitorService;

class ConsumeServersController extends Controller
{
    protected const METRICS = ['cpu', 'memory', 'network', 'uptime'];
    protected const CACHE_TTL = 20;

    public function index(Request $request, ResourceMonitorService $monitor)
    {
        $limits = ConsumeServerLimit::with('server')->orderByDesc('created_at')
            ->paginate(25, ['*'], 'limits_page')
            ->withQueryString();
        $servers = Server::orderBy('name')->get(['id', 'name']);
        $nodes = Node::orderBy('name')->get(['id', 'name']);

        $metric = $request->query('metric', 'cpu');
        if (!in_array($metric, self::METRICS, true)) {
            $metric = 'cpu';
        }

        $nodeId = $request->filled('node_id') ? (int) $request->query('node_id') : null;
        $topLimit = (int) $request->query('limit', 15);
        $topLimit = max(5, min(50, $topLimit));

        // Cachea el ranking unos segundos: consultar Wings servidor por
        // servidor en cada carga de la pagina seria demasiado lento con
        // muchos servidores, y no hace falta que el dato sea al segundo.
        // "Actualizar" en la interfaz simplemente borra esta clave antes de
        // volver a pedir los datos.
        $cacheKey = "consumeservers.top.{$metric}.{$nodeId}.{$topLimit}";

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        $generatedAt = Cache::get($cacheKey . '.time');
        $topServers = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($monitor, $metric, $nodeId, $topLimit, $cacheKey) {
            Cache::put($cacheKey . '.time', now(), self::CACHE_TTL);

            return $monitor->topConsumers($metric, $nodeId, $topLimit);
        });
        $generatedAt = $generatedAt ?? Cache::get($cacheKey . '.time') ?? now();

        $stats = [
            'total' => ConsumeServerLimit::query()->count(),
            'active' => ConsumeServerLimit::query()->where('enabled', true)->count(),
            'triggered_today' => ConsumeServerLimit::query()->whereDate('triggered_at', now()->toDateString())->count(),
        ];

        return view('consumeservers::index', [
            'limits' => $limits,
            'servers' => $servers,
            'nodes' => $nodes,
            'topServers' => $topServers,
            'metric' => $metric,
            'nodeId' => $nodeId,
            'topLimit' => $topLimit,
            'generatedAt' => $generatedAt,
            'stats' => $stats,
        ]);
    }

    /**
     * Ejecuta la revision de todos los limites al instante, sin esperar al
     * minuto del cron. Sirve tanto para forzar el apagado/suspension ya
     * mismo como para diagnosticar por que un limite "no salta": si aqui
     * SI se dispara, el problema es que el cron `schedule:run` del panel no
     * esta corriendo; si tampoco se dispara aqui, el problema esta en el
     * limite o en la lectura de Wings (mira la columna "Error").
     */
    public function checkNow(ResourceMonitorService $monitor)
    {
        $report = $monitor->checkAll();

        return redirect()->route('admin.extensions.consumeservers.index')
            ->with('checkReport', $report);
    }

    public function toggle(ConsumeServerLimit $limit)
    {
        $limit->update(['enabled' => !$limit->enabled]);

        return redirect()->route('admin.extensions.consumeservers.index')
            ->with('success', 'Limite ' . ($limit->enabled ? 'activado' : 'desactivado') . '.');
    }

    /**
     * Apagado inmediato desde el boton de la tabla de "mas consumen": no
     * crea ni depende de ningun limite configurado.
     */
    public function power(Request $request, Server $server, ResourceMonitorService $monitor)
    {
        $monitor->powerOff($server);

        return redirect()->back()->with('success', "Orden de apagado enviada a {$server->name}.");
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'metric' => 'required|in:cpu,memory,network,uptime',
            'period' => 'required|in:instant,daily,monthly',
            'threshold_value' => 'required|integer|min:1',
            'action' => 'required|in:stop,suspend',
            'notify_admin' => 'boolean',
        ]);

        $data['notify_admin'] = $request->boolean('notify_admin');
        $data['enabled'] = true;

        ConsumeServerLimit::create($data);

        return redirect()->route('admin.extensions.consumeservers.index')->with('success', 'Limite creado.');
    }

    public function update(Request $request, ConsumeServerLimit $limit)
    {
        $data = $request->validate([
            'threshold_value' => 'required|integer|min:1',
            'action' => 'required|in:stop,suspend',
            'notify_admin' => 'boolean',
            'enabled' => 'boolean',
        ]);

        $data['notify_admin'] = $request->boolean('notify_admin');
        $data['enabled'] = $request->boolean('enabled');

        $limit->update($data);

        return redirect()->route('admin.extensions.consumeservers.index')->with('success', 'Limite actualizado.');
    }

    public function destroy(ConsumeServerLimit $limit)
    {
        $limit->delete();

        return redirect()->route('admin.extensions.consumeservers.index')->with('success', 'Limite eliminado.');
    }
}
