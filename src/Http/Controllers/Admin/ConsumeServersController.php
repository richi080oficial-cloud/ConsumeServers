<?php

namespace Pterodactyl\Extensions\ConsumeServers\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Extensions\ConsumeServers\Models\ConsumeServerLimit;

class ConsumeServersController extends Controller
{
    public function index()
    {
        $limits = ConsumeServerLimit::with('server')->orderByDesc('created_at')->paginate(25);
        $servers = Server::orderBy('name')->get(['id', 'name']);

        return view('consumeservers::index', [
            'limits' => $limits,
            'servers' => $servers,
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
