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
        $cacheKey = "consumeservers.top.{$metric}.{$nodeId}.{$topLimit}";
        $topServers = Cache::remember($cacheKey, 20, function () use ($monitor, $metric, $nodeId, $topLimit) {
            return $monitor->topConsumers($metric, $nodeId, $topLimit);
        });

        return view('consumeservers::index', [
            'limits' => $limits,
            'servers' => $servers,
            'nodes' => $nodes,
            'topServers' => $topServers,
            'metric' => $metric,
            'nodeId' => $nodeId,
            'topLimit' => $topLimit,
        ]);
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
