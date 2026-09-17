<?php

namespace Pterodactyl\Extensions\ConsumeServers\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Extensions\ConsumeServers\Models\ConsumeServerLimit;
use Pterodactyl\Extensions\ConsumeServers\Services\ResourceMonitorService;
use Pterodactyl\Extensions\ConsumeServers\Support\Units;

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

        [$metric, $nodeId, $topLimit] = $this->normalizeFilters($request);
        [$topServers, $generatedAt] = $this->cachedTopRows($monitor, $metric, $nodeId, $topLimit, $request->boolean('refresh'));

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
     * Version en JSON del ranking, usada por el auto-refresco de la
     * pantalla (fetch() cada pocos segundos) para animar los numeros sin
     * recargar la pagina entera.
     */
    public function data(Request $request, ResourceMonitorService $monitor)
    {
        [$metric, $nodeId, $topLimit] = $this->normalizeFilters($request);
        [$topServers, $generatedAt] = $this->cachedTopRows($monitor, $metric, $nodeId, $topLimit, $request->boolean('refresh'));

        return response()->json([
            'generated_at' => $generatedAt->toIso8601String(),
            'metric' => $metric,
            'rows' => array_values(array_map(
                fn ($row, $index) => $this->serializeRow($row, $index, $metric),
                $topServers,
                array_keys($topServers)
            )),
        ]);
    }

    protected function normalizeFilters(Request $request): array
    {
        $metric = $request->query('metric', 'cpu');
        if (!in_array($metric, self::METRICS, true)) {
            $metric = 'cpu';
        }

        $nodeId = $request->filled('node_id') ? (int) $request->query('node_id') : null;
        $topLimit = (int) $request->query('limit', 15);
        $topLimit = max(5, min(50, $topLimit));

        return [$metric, $nodeId, $topLimit];
    }

    /**
     * Cachea el ranking unos segundos: consultar Wings servidor por
     * servidor en cada carga seria demasiado lento con muchos servidores,
     * y no hace falta que el dato sea al segundo. "Actualizar ahora" (o
     * ?refresh=1 desde el auto-refresco) invalida esta clave antes de
     * volver a pedir los datos.
     *
     * @return array{0: array, 1: \Illuminate\Support\Carbon}
     */
    protected function cachedTopRows(ResourceMonitorService $monitor, string $metric, ?int $nodeId, int $topLimit, bool $forceRefresh): array
    {
        $cacheKey = "consumeservers.top.{$metric}.{$nodeId}.{$topLimit}";

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $generatedAt = Cache::get($cacheKey . '.time');
        $topServers = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($monitor, $metric, $nodeId, $topLimit, $cacheKey) {
            Cache::put($cacheKey . '.time', now(), self::CACHE_TTL);

            return $monitor->topConsumers($metric, $nodeId, $topLimit);
        });
        $generatedAt = $generatedAt ?? Cache::get($cacheKey . '.time') ?? now();

        return [$topServers, $generatedAt];
    }

    /**
     * Convierte una fila de topConsumers() (con el modelo Server tal cual)
     * en un array plano y ya formateado, compartido entre la carga inicial
     * de la vista y el endpoint JSON del auto-refresco — asi el numero que
     * se anima en el navegador es siempre el mismo que se ve al recargar
     * la pagina.
     */
    protected function serializeRow(array $row, int $index, string $metric): array
    {
        $server = $row['server'];
        $value = $row['value'];
        $cpuRelative = $row['cpu_relative'] ?? null;
        $cpuLimit = $row['cpu_limit'] ?? null;

        $barPercent = $metric === 'cpu' ? Units::cpuBarPercent($value, $cpuRelative) : null;
        $barClass = $barPercent === null ? null : ($barPercent >= 90 ? 'progress-bar-danger' : ($barPercent >= 60 ? 'progress-bar-warning' : 'progress-bar-success'));

        if ($metric === 'cpu') {
            $secondary = $cpuRelative !== null
                ? "{$cpuRelative}% de su limite de {$cpuLimit}%"
                : 'sin limite asignado';
        } elseif (in_array($metric, ['memory', 'network'], true)) {
            $secondary = Units::megabytes($value);
        } else {
            $secondary = null;
        }

        return [
            'position' => $index + 1,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'owner' => $server->user->username ?? $server->user->email ?? null,
            'owner_email' => $server->user->email ?? null,
            'node_name' => $server->node->name ?? 'N/A',
            'value' => $value,
            'unit' => Units::shortUnit($metric),
            'secondary' => $secondary,
            'bar_percent' => $barPercent,
            'bar_class' => $barClass,
            'view_url' => url('/admin/servers/view/' . $server->id),
            'power_url' => route('admin.extensions.consumeservers.servers.power', $server),
        ];
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

        if ($request->wantsJson()) {
            return response()->json(['message' => "Orden de apagado enviada a {$server->name}."]);
        }

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
