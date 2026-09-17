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

    @if (session('checkReport'))
        @php $report = session('checkReport'); @endphp
        <div class="box box-solid box-info">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-stethoscope"></i> Resultado de "Revisar limites ahora"</h3>
            </div>
            <div class="box-body table-responsive no-padding">
                @if (empty($report))
                    <p class="text-muted" style="padding: 10px;">No hay ningun limite activo que revisar.</p>
                @else
                    <table class="table table-condensed">
                        <thead>
                        <tr>
                            <th>Servidor</th>
                            <th>Metrica</th>
                            <th>Valor actual</th>
                            <th>Umbral</th>
                            <th>Disparado</th>
                            <th>Error</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($report as $row)
                            <tr>
                                <td>{{ $row['server'] }}</td>
                                <td>{{ Units::label($row['limit']->metric) }}</td>
                                <td>{{ $row['current'] === null ? '—' : Units::humanize($row['limit']->metric, $row['current']) }}</td>
                                <td>{{ Units::humanize($row['limit']->metric, $row['threshold']) }}</td>
                                <td>
                                    @if ($row['triggered'])
                                        <span class="label label-danger">SI, se aplico la accion</span>
                                    @else
                                        <span class="label label-default">no</span>
                                    @endif
                                </td>
                                <td class="text-red">{{ $row['error'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            <div class="box-footer">
                <p class="text-muted" style="margin: 0;">
                    <i class="fa fa-info-circle"></i>
                    Si aqui un limite SI se dispara pero el servidor sigue sin apagarse solo, el chequeo automatico de cada
                    minuto probablemente no esta corriendo: revisa que el cron
                    <code>* * * * * php artisan schedule:run</code> este puesto en el servidor, o mira
                    <code>storage/logs/consumeservers.log</code> y <code>storage/logs/laravel-{{ now()->format('Y-m-d') }}.log</code>.
                </p>
            </div>
        </div>
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
        <div class="col-xs-12 text-right" style="margin-bottom: 10px;">
            <form action="{{ route('admin.extensions.consumeservers.check-now') }}" method="POST" style="display:inline;">
                @csrf
                <button type="submit" class="btn btn-warning">
                    <i class="fa fa-stethoscope"></i> Revisar limites ahora
                </button>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box box-danger" id="cs-top-box"
                 data-refresh-url="{{ route('admin.extensions.consumeservers.data') }}"
                 data-metric="{{ $metric }}" data-node-id="{{ $nodeId }}" data-limit="{{ $topLimit }}"
                 data-metric-icon="{{ Units::icon($metric) }}">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-line-chart"></i> Servidores que mas consumen</h3>
                    <div class="box-tools">
                        <label class="cs-autorefresh-toggle" style="font-weight: normal; margin-right: 10px;">
                            <input type="checkbox" id="cs-autorefresh" checked>
                            Auto-actualizar cada <span id="cs-interval-label">10</span>s
                        </label>
                        <span class="label label-default" id="cs-generated-at" data-timestamp="{{ $generatedAt->timestamp }}">
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
                                <th>Owner</th>
                                <th>Nodo</th>
                                <th style="width: 260px;">Consumo</th>
                                <th style="width: 190px;"></th>
                            </tr>
                            </thead>
                            <tbody id="cs-top-body">
                            @forelse ($topServers as $index => $row)
                                @php
                                    $value = $row['value'];
                                    $cpuRelative = $row['cpu_relative'] ?? null;
                                    $barPercent = $metric === 'cpu' ? Units::cpuBarPercent($value, $cpuRelative) : null;
                                    $barClass = $barPercent === null ? '' : ($barPercent >= 90 ? 'progress-bar-danger' : ($barPercent >= 60 ? 'progress-bar-warning' : 'progress-bar-success'));
                                    $secondary = $metric === 'cpu'
                                        ? ($cpuRelative !== null ? "{$cpuRelative}% de su limite de {$row['cpu_limit']}%" : 'sin limite asignado')
                                        : (in_array($metric, ['memory', 'network']) ? Units::megabytes($value) : null);
                                @endphp
                                <tr data-server-id="{{ $row['server']->id }}" data-value="{{ $value }}">
                                    <td class="cs-position">
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
                                    <td>
                                        @if ($row['server']->user)
                                            <span title="{{ $row['server']->user->email }}">{{ $row['server']->user->username ?? $row['server']->user->email }}</span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>{{ $row['server']->node->name ?? 'N/A' }}</td>
                                    <td>
                                        <strong><span class="cs-value">{{ $value }}</span> <span class="cs-unit">{{ Units::shortUnit($metric) }}</span></strong>
                                        @if ($secondary)
                                            <span class="text-muted cs-secondary">({{ $secondary }})</span>
                                        @endif
                                        <div class="progress progress-xs cs-bar-wrap" style="margin-top: 4px; margin-bottom: 0; {{ $barPercent === null ? 'visibility:hidden;' : '' }}">
                                            <div class="progress-bar cs-bar {{ $barClass }}" style="width: {{ $barPercent ?? 0 }}%;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="{{ url('/admin/servers/view/' . $row['server']->id) }}" class="btn btn-xs btn-default" title="Ver servidor">
                                                <i class="fa fa-eye"></i> Ver
                                            </a>
                                            <a href="#nuevo-limite" class="btn btn-xs btn-primary preset-limit"
                                               data-server-id="{{ $row['server']->id }}" data-metric="{{ $metric }}" title="Crear limite para este servidor">
                                                <i class="fa fa-plus"></i> Limite
                                            </a>
                                            <form action="{{ route('admin.extensions.consumeservers.servers.power', $row['server']) }}" method="POST"
                                                  onsubmit="return confirm('¿Apagar {{ $row['server']->name }} ahora mismo?');" style="display:inline;">
                                                @csrf
                                                <button type="submit" class="btn btn-xs btn-danger" title="Apagar servidor">
                                                    <i class="fa fa-power-off"></i> Apagar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">
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

    <style>
        #cs-top-body tr { transition: background-color 0.6s ease; }
        #cs-top-body tr.cs-flash-up { background-color: rgba(221, 75, 57, 0.18); }
        #cs-top-body tr.cs-flash-down { background-color: rgba(0, 166, 90, 0.18); }
        #cs-top-body tr.cs-flash-new { background-color: rgba(0, 122, 204, 0.18); }
        #cs-top-body .cs-value { display: inline-block; min-width: 1.5em; }
        #cs-top-body .cs-bar-wrap { transition: opacity 0.3s ease; }
        .cs-autorefresh-toggle input { margin-right: 4px; vertical-align: middle; }
    </style>

    <script>
        window.CS_CSRF_FIELD = '<input type="hidden" name="_token" value="{{ csrf_token() }}">';

        (function () {
            var box = document.getElementById('cs-top-box');
            if (!box) {
                return;
            }

            var tbody = document.getElementById('cs-top-body');
            var generatedAtLabel = document.getElementById('cs-generated-at');
            var autoRefreshCheckbox = document.getElementById('cs-autorefresh');
            var refreshUrl = box.dataset.refreshUrl;
            var metricIcon = box.dataset.metricIcon;
            var intervalMs = 10000;
            var timer = null;

            // --- "Actualizado hace X" en vivo, sin esperar al proximo poll ---
            function tickAgo() {
                if (!generatedAtLabel) return;
                var ts = parseInt(generatedAtLabel.dataset.timestamp, 10);
                if (!ts) return;
                var diff = Math.max(0, Math.round(Date.now() / 1000) - ts);
                var text = diff < 5 ? 'justo ahora' : (diff < 60 ? diff + 's' : Math.round(diff / 60) + 'min');
                generatedAtLabel.innerHTML = '<i class="fa fa-refresh"></i> Actualizado hace ' + text;
            }
            setInterval(tickAgo, 1000);

            // --- animacion de conteo del numero principal (sube o baja) ---
            function animateValue(el, from, to, duration) {
                if (from === to) {
                    el.textContent = to;
                    return;
                }
                var start = performance.now();
                function step(now) {
                    var progress = Math.min(1, (now - start) / duration);
                    var eased = 1 - Math.pow(1 - progress, 3); // easeOutCubic
                    var current = Math.round(from + (to - from) * eased);
                    el.textContent = current;
                    if (progress < 1) {
                        requestAnimationFrame(step);
                    } else {
                        el.textContent = to;
                    }
                }
                requestAnimationFrame(step);
            }

            function flash(row, cls) {
                row.classList.remove('cs-flash-up', 'cs-flash-down', 'cs-flash-new');
                // fuerza reflow para que la transicion se vea aunque se repita la misma clase
                void row.offsetWidth;
                row.classList.add(cls);
                setTimeout(function () {
                    row.classList.remove(cls);
                }, 900);
            }

            function buildRow(data) {
                var tr = document.createElement('tr');
                tr.dataset.serverId = data.server_id;
                tr.dataset.value = data.value;

                var ownerHtml = data.owner
                    ? '<span title="' + (data.owner_email || '') + '">' + data.owner + '</span>'
                    : '<span class="text-muted">N/A</span>';

                var barStyle = data.bar_percent === null ? 'visibility:hidden;' : '';
                var barWidth = data.bar_percent === null ? 0 : data.bar_percent;
                var barClass = data.bar_class || '';

                var secondaryHtml = data.secondary
                    ? '<span class="text-muted cs-secondary">(' + data.secondary + ')</span>'
                    : '';

                tr.innerHTML =
                    '<td class="cs-position">' + data.position + '</td>' +
                    '<td><i class="fa ' + metricIcon + ' text-muted"></i> ' + escapeHtml(data.server_name) + '</td>' +
                    '<td>' + ownerHtml + '</td>' +
                    '<td>' + escapeHtml(data.node_name) + '</td>' +
                    '<td><strong><span class="cs-value">' + data.value + '</span> <span class="cs-unit">' + data.unit + '</span></strong> ' +
                        secondaryHtml +
                        '<div class="progress progress-xs cs-bar-wrap" style="margin-top:4px;margin-bottom:0;' + barStyle + '">' +
                            '<div class="progress-bar cs-bar ' + barClass + '" style="width:' + barWidth + '%;"></div>' +
                        '</div>' +
                    '</td>' +
                    '<td>' +
                        '<div class="btn-group">' +
                            '<a href="' + data.view_url + '" class="btn btn-xs btn-default" title="Ver servidor"><i class="fa fa-eye"></i> Ver</a>' +
                            '<a href="#nuevo-limite" class="btn btn-xs btn-primary preset-limit" data-server-id="' + data.server_id + '" data-metric="' + box.dataset.metric + '" title="Crear limite para este servidor"><i class="fa fa-plus"></i> Limite</a>' +
                            '<form action="' + data.power_url + '" method="POST" onsubmit="return confirm(\'¿Apagar ' + escapeJs(data.server_name) + ' ahora mismo?\');" style="display:inline;">' +
                                (window.CS_CSRF_FIELD || '') +
                                '<button type="submit" class="btn btn-xs btn-danger" title="Apagar servidor"><i class="fa fa-power-off"></i> Apagar</button>' +
                            '</form>' +
                        '</div>' +
                    '</td>';

                bindPreset(tr.querySelector('.preset-limit'));

                return tr;
            }

            function escapeHtml(str) {
                var div = document.createElement('div');
                div.textContent = str == null ? '' : str;
                return div.innerHTML;
            }

            function escapeJs(str) {
                return String(str == null ? '' : str).replace(/'/g, "\\'");
            }

            function bindPreset(link) {
                if (!link) return;
                link.addEventListener('click', function () {
                    document.getElementById('new-limit-server').value = this.dataset.serverId;
                    document.getElementById('new-limit-metric').value = this.dataset.metric;
                    document.getElementById('new-limit-metric').dispatchEvent(new Event('change'));
                });
            }

            function refresh() {
                var params = new URLSearchParams({
                    metric: box.dataset.metric,
                    limit: box.dataset.limit
                });
                if (box.dataset.nodeId) {
                    params.set('node_id', box.dataset.nodeId);
                }

                fetch(refreshUrl + '?' + params.toString(), {
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
                    credentials: 'same-origin'
                })
                    .then(function (res) { return res.ok ? res.json() : Promise.reject(res.status); })
                    .then(applyUpdate)
                    .catch(function () { /* silencioso: se reintenta en el proximo tick */ });
            }

            function applyUpdate(payload) {
                if (generatedAtLabel) {
                    generatedAtLabel.dataset.timestamp = Math.round(new Date(payload.generated_at).getTime() / 1000);
                    tickAgo();
                }

                var existingRows = {};
                Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-server-id]'), function (tr) {
                    existingRows[tr.dataset.serverId] = tr;
                });

                // FLIP: posiciones actuales antes de reordenar/actualizar.
                var firstRects = {};
                Object.keys(existingRows).forEach(function (id) {
                    firstRects[id] = existingRows[id].getBoundingClientRect();
                });

                var seenIds = {};
                var previousRow = null;

                payload.rows.forEach(function (data) {
                    seenIds[data.server_id] = true;
                    var row = existingRows[data.server_id];

                    if (!row) {
                        row = buildRow(data);
                        flash(row, 'cs-flash-new');
                    } else {
                        var oldValue = parseFloat(row.dataset.value);
                        var newValue = data.value;

                        var valueEl = row.querySelector('.cs-value');
                        if (valueEl) {
                            animateValue(valueEl, oldValue, newValue, 700);
                        }

                        var secondaryEl = row.querySelector('.cs-secondary');
                        if (data.secondary) {
                            if (secondaryEl) {
                                secondaryEl.textContent = '(' + data.secondary + ')';
                            }
                        } else if (secondaryEl) {
                            secondaryEl.textContent = '';
                        }

                        var bar = row.querySelector('.cs-bar');
                        var barWrap = row.querySelector('.cs-bar-wrap');
                        if (bar && barWrap) {
                            if (data.bar_percent === null) {
                                barWrap.style.visibility = 'hidden';
                            } else {
                                barWrap.style.visibility = 'visible';
                                bar.style.width = data.bar_percent + '%';
                                bar.className = 'progress-bar cs-bar ' + (data.bar_class || '');
                            }
                        }

                        var posCell = row.querySelector('.cs-position');
                        if (posCell) {
                            posCell.innerHTML = data.position === 1 ? '<span class="label label-danger">#1</span>' : data.position;
                        }

                        row.dataset.value = newValue;

                        if (newValue > oldValue) {
                            flash(row, 'cs-flash-up');
                        } else if (newValue < oldValue) {
                            flash(row, 'cs-flash-down');
                        }
                    }

                    // Reordena en el DOM segun el orden que manda el servidor.
                    if (previousRow) {
                        previousRow.after(row);
                    } else {
                        tbody.prepend(row);
                    }
                    previousRow = row;
                });

                // Quita filas de servidores que ya no estan en el top N.
                Object.keys(existingRows).forEach(function (id) {
                    if (!seenIds[id]) {
                        existingRows[id].remove();
                    }
                });

                // FLIP: anima el desplazamiento de las filas que cambiaron de sitio.
                Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-server-id]'), function (tr) {
                    var id = tr.dataset.serverId;
                    var first = firstRects[id];
                    if (!first) return;
                    var last = tr.getBoundingClientRect();
                    var deltaY = first.top - last.top;
                    if (Math.abs(deltaY) < 1) return;
                    tr.style.transition = 'none';
                    tr.style.transform = 'translateY(' + deltaY + 'px)';
                    requestAnimationFrame(function () {
                        tr.style.transition = 'transform 0.4s ease';
                        tr.style.transform = '';
                    });
                });
            }

            function scheduleNext() {
                if (timer) clearTimeout(timer);
                if (autoRefreshCheckbox && autoRefreshCheckbox.checked) {
                    timer = setTimeout(function () {
                        refresh();
                        scheduleNext();
                    }, intervalMs);
                }
            }

            if (autoRefreshCheckbox) {
                autoRefreshCheckbox.addEventListener('change', scheduleNext);
            }

            tickAgo();
            scheduleNext();
        })();
    </script>
@endsection
