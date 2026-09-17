@extends('layouts.admin')

@section('title')
    Consume Servers
@endsection

@section('content-header')
    <h1>Consume Servers <small>Limites de consumo por servidor.</small></h1>
@endsection

@section('content')
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row">
        <div class="col-xs-12">
            <div class="box box-danger">
                <div class="box-header with-border">
                    <h3 class="box-title">Servidores que mas consumen</h3>
                </div>
                <form action="{{ route('admin.extensions.consumeservers.index') }}" method="GET">
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-3">
                                <label>Metrica</label>
                                <select name="metric" class="form-control" onchange="this.form.submit()">
                                    <option value="cpu" {{ $metric === 'cpu' ? 'selected' : '' }}>CPU (%)</option>
                                    <option value="memory" {{ $metric === 'memory' ? 'selected' : '' }}>Memoria (MB)</option>
                                    <option value="network" {{ $metric === 'network' ? 'selected' : '' }}>Red acumulada (MB)</option>
                                    <option value="uptime" {{ $metric === 'uptime' ? 'selected' : '' }}>Uptime (min)</option>
                                </select>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Nodo</label>
                                <select name="node_id" class="form-control" onchange="this.form.submit()">
                                    <option value="">Todos los nodos</option>
                                    @foreach ($nodes as $node)
                                        <option value="{{ $node->id }}" {{ $nodeId === $node->id ? 'selected' : '' }}>{{ $node->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Mostrar</label>
                                <select name="limit" class="form-control" onchange="this.form.submit()">
                                    @foreach ([10, 15, 25, 50] as $option)
                                        <option value="{{ $option }}" {{ $topLimit === $option ? 'selected' : '' }}>Top {{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-default form-control">Filtrar</button>
                            </div>
                        </div>
                    </div>
                    <div class="box-body table-responsive no-padding">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Servidor</th>
                                <th>Nodo</th>
                                <th>Consumo</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($topServers as $index => $row)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $row['server']->name }}</td>
                                    <td>{{ $row['server']->node->name ?? 'N/A' }}</td>
                                    <td>
                                        <strong>{{ $row['value'] }}</strong>
                                        {{ ['cpu' => '%', 'memory' => 'MB', 'network' => 'MB', 'uptime' => 'min'][$metric] }}
                                    </td>
                                    <td>
                                        <a href="#nuevo-limite" class="btn btn-xs btn-primary preset-limit"
                                           data-server-id="{{ $row['server']->id }}" data-metric="{{ $metric }}">
                                            Crear limite
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center">No se pudo leer el consumo de ningun servidor (revisa que Wings responda).</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

            <div class="box box-primary" id="nuevo-limite">
                <div class="box-header with-border">
                    <h3 class="box-title">Nuevo limite</h3>
                </div>
                <form action="{{ route('admin.extensions.consumeservers.store') }}" method="POST">
                    @csrf
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-3">
                                <label>Servidor</label>
                                <select name="server_id" id="new-limit-server" class="form-control" required>
                                    @foreach ($servers as $server)
                                        <option value="{{ $server->id }}">{{ $server->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Metrica</label>
                                <select name="metric" id="new-limit-metric" class="form-control" required>
                                    <option value="cpu">CPU (%)</option>
                                    <option value="memory">Memoria (MB)</option>
                                    <option value="network">Red (MB)</option>
                                    <option value="uptime">Uptime (min)</option>
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Periodo</label>
                                <select name="period" class="form-control" required>
                                    <option value="instant">Instantaneo</option>
                                    <option value="daily">Diario</option>
                                    <option value="monthly">Mensual</option>
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Umbral</label>
                                <input type="number" name="threshold_value" class="form-control" min="1" required>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Accion</label>
                                <select name="action" class="form-control" required>
                                    <option value="stop">Apagar</option>
                                    <option value="suspend">Suspender</option>
                                </select>
                            </div>
                            <div class="form-group col-md-1">
                                <label>Notificar</label><br>
                                <input type="checkbox" name="notify_admin" value="1" checked>
                            </div>
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary">Crear limite</button>
                    </div>
                </form>
            </div>

            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Limites configurados</h3>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                        <tr>
                            <th>Servidor</th>
                            <th>Metrica</th>
                            <th>Periodo</th>
                            <th>Umbral</th>
                            <th>Accion</th>
                            <th>Activo</th>
                            <th>Ultima vez activado</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($limits as $limit)
                            <tr>
                                <td>{{ $limit->server->name ?? 'N/A' }}</td>
                                <td>{{ strtoupper($limit->metric) }}</td>
                                <td>{{ $limit->period }}</td>
                                <td>{{ $limit->threshold_value }}</td>
                                <td>{{ $limit->action }}</td>
                                <td>{{ $limit->enabled ? 'Si' : 'No' }}</td>
                                <td>{{ $limit->triggered_at ?? '—' }}</td>
                                <td>
                                    <form action="{{ route('admin.extensions.consumeservers.destroy', $limit) }}" method="POST" onsubmit="return confirm('¿Eliminar este limite?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-xs btn-danger">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center">No hay limites configurados todavia.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="box-footer">
                    {{ $limits->links() }}
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.preset-limit').forEach(function (link) {
            link.addEventListener('click', function () {
                document.getElementById('new-limit-server').value = this.dataset.serverId;
                document.getElementById('new-limit-metric').value = this.dataset.metric;
            });
        });
    </script>
@endsection
