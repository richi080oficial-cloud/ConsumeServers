@extends('layouts.admin')

@php
    use Pterodactyl\Extensions\ConsumeServers\Support\Units;
@endphp

@section('title')
    Consume Servers
@endsection

@section('content-header')
    <h1>Consume Servers <small>Consumo de recursos y limites automaticos por servidor.</small></h1>
@endsection

@section('content')
    @if (session('success'))
        <div class="alert alert-success"><i class="fa fa-check"></i> {{ session('success') }}</div>
    @endif

    <div class="row">
        <div class="col-md-4 col-sm-6 col-xs-12">
            <div class="small-box bg-aqua">
                <div class="inner">
                    <h3>{{ $stats['total'] }}</h3>
                    <p>Limites configurados</p>
                </div>
                <div class="icon"><i class="fa fa-list"></i></div>
            </div>
        </div>
        <div class="col-md-4 col-sm-6 col-xs-12">
            <div class="small-box bg-green">
                <div class="inner">
                    <h3>{{ $stats['active'] }}</h3>
                    <p>Limites activos</p>
                </div>
                <div class="icon"><i class="fa fa-toggle-on"></i></div>
            </div>
        </div>
        <div class="col-md-4 col-sm-6 col-xs-12">
            <div class="small-box {{ $stats['triggered_today'] > 0 ? 'bg-red' : 'bg-gray' }}">
                <div class="inner">
                    <h3>{{ $stats['triggered_today'] }}</h3>
                    <p>Disparados hoy</p>
                </div>
                <div class="icon"><i class="fa fa-bolt"></i></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box box-danger">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-line-chart"></i> Servidores que mas consumen</h3>
                    <div class="box-tools">
                        <span class="label label-default">
                            <i class="fa fa-refresh"></i>
                            Actualizado {{ $generatedAt->diffForHumans() }}
                        </span>
                    </div>
                </div>
                <form action="{{ route('admin.extensions.consumeservers.index') }}" method="GET">
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-3">
                                <label>Metrica</label>
                                <select name="metric" class="form-control" onchange="this.form.submit()">
                                    @foreach (['cpu' => 'CPU (%)', 'memory' => 'Memoria', 'network' => 'Red acumulada', 'uptime' => 'Uptime'] as $value => $labelText)
                                        <option value="{{ $value }}" {{ $metric === $value ? 'selected' : '' }}>{{ $labelText }}</option>
                                    @endforeach
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
                                <button type="submit" class="btn btn-default form-control"><i class="fa fa-filter"></i> Filtrar</button>
                            </div>
                            <div class="form-group col-md-2">
                                <label>&nbsp;</label>
                                <a href="{{ request()->fullUrlWithQuery(['refresh' => 1]) }}" class="btn btn-default form-control">
                                    <i class="fa fa-refresh"></i> Actualizar ahora
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="box-body table-responsive no-padding">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Servidor</th>
                                <th>Nodo</th>
                                <th style="width: 280px;">Consumo</th>
                                <th style="width: 110px;"></th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($topServers as $index => $row)
                                @php
                                    $value = $row['value'];
                                    $barPercent = $metric === 'cpu' ? min(100, $value) : null;
                                    $barClass = $barPercent === null ? '' : ($barPercent >= 90 ? 'progress-bar-danger' : ($barPercent >= 60 ? 'progress-bar-warning' : 'progress-bar-success'));
                                @endphp
                                <tr>
                                    <td>
                                        @if ($index === 0)
                                            <span class="label label-danger">#1</span>
                                        @else
                                            {{ $index + 1 }}
                                        @endif
                                    </td>
                                    <td>
                                        <i class="fa {{ Units::icon($metric) }} text-muted"></i>
                                        {{ $row['server']->name }}
                                    </td>
                                    <td>{{ $row['server']->node->name ?? 'N/A' }}</td>
                                    <td>
                                        <strong>{{ Units::humanize($metric, $value) }}</strong>
                                        @if ($metric === 'memory' || $metric === 'network')
                                            <span class="text-muted">({{ $value }} MB)</span>
                                        @endif
                                        @if ($barPercent !== null)
                                            <div class="progress progress-xs" style="margin-top: 4px; margin-bottom: 0;">
                                                <div class="progress-bar {{ $barClass }}" style="width: {{ $barPercent }}%;"></div>
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="#nuevo-limite" class="btn btn-xs btn-primary preset-limit"
                                           data-server-id="{{ $row['server']->id }}" data-metric="{{ $metric }}">
                                            <i class="fa fa-plus"></i> Limite
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted">
                                        <i class="fa fa-exclamation-triangle"></i>
                                        No se pudo leer el consumo de ningun servidor (revisa que Wings responda).
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

            <div class="box box-primary" id="nuevo-limite">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-plus-circle"></i> Nuevo limite</h3>
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
                                    <option value="memory">Memoria</option>
                                    <option value="network">Red</option>
                                    <option value="uptime">Uptime</option>
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
                                <label>Umbral (<span id="new-limit-unit">%</span>)</label>
                                <input type="number" name="threshold_value" id="new-limit-value" class="form-control" min="1" required>
                                <p class="help-block" id="new-limit-hint" style="min-height: 17px;"></p>
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
                        <p class="text-muted">
                            <i class="fa fa-info-circle"></i>
                            Para <strong>Memoria</strong> y <strong>Red</strong> el umbral se introduce siempre en <strong>MB</strong>
                            (1 GB = 1024 MB) — el campo de abajo te muestra el equivalente en GB mientras escribes.
                        </p>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Crear limite</button>
                    </div>
                </form>
            </div>

            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-list"></i> Limites configurados</h3>
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
                            <th>Estado</th>
                            <th>Ultima vez activado</th>
                            <th style="width: 140px;"></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($limits as $limit)
                            <tr>
                                <td>{{ $limit->server->name ?? 'N/A' }}</td>
                                <td>
                                    <i class="fa {{ Units::icon($limit->metric) }} text-muted"></i>
                                    {{ Units::label($limit->metric) }}
                                </td>
                                <td>{{ ucfirst($limit->period) }}</td>
                                <td>
                                    {{ Units::humanize($limit->metric, $limit->threshold_value) }}
                                    @if (in_array($limit->metric, ['memory', 'network']))
                                        <span class="text-muted">({{ $limit->threshold_value }} MB)</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="label {{ $limit->action === 'suspend' ? 'label-warning' : 'label-danger' }}">
                                        {{ $limit->action === 'suspend' ? 'Suspender' : 'Apagar' }}
                                    </span>
                                </td>
                                <td>
                                    <form action="{{ route('admin.extensions.consumeservers.toggle', $limit) }}" method="POST" style="display:inline;">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-xs {{ $limit->enabled ? 'btn-success' : 'btn-default' }}">
                                            <i class="fa {{ $limit->enabled ? 'fa-toggle-on' : 'fa-toggle-off' }}"></i>
                                            {{ $limit->enabled ? 'Activo' : 'Inactivo' }}
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    @if ($limit->triggered_at)
                                        <span class="label label-danger">{{ $limit->triggered_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-muted">Nunca</span>
                                    @endif
                                </td>
                                <td>
                                    <form action="{{ route('admin.extensions.consumeservers.destroy', $limit) }}" method="POST" onsubmit="return confirm('¿Eliminar este limite?');" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted">No hay limites configurados todavia.</td>
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
        (function () {
            var units = {cpu: '%', memory: 'MB', network: 'MB', uptime: 'min'};

            var serverSelect = document.getElementById('new-limit-server');
            var metricSelect = document.getElementById('new-limit-metric');
            var unitLabel = document.getElementById('new-limit-unit');
            var valueInput = document.getElementById('new-limit-value');
            var hint = document.getElementById('new-limit-hint');

            function updateHint() {
                var metric = metricSelect.value;
                unitLabel.textContent = units[metric] || '';

                if ((metric === 'memory' || metric === 'network') && valueInput.value) {
                    var mb = parseInt(valueInput.value, 10) || 0;
                    hint.textContent = '= ' + (mb / 1024).toFixed(2) + ' GB';
                } else {
                    hint.textContent = '';
                }
            }

            metricSelect.addEventListener('change', updateHint);
            valueInput.addEventListener('input', updateHint);

            document.querySelectorAll('.preset-limit').forEach(function (link) {
                link.addEventListener('click', function () {
                    serverSelect.value = this.dataset.serverId;
                    metricSelect.value = this.dataset.metric;
                    updateHint();
                });
            });
        })();
    </script>
@endsection
