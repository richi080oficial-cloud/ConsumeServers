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
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Nuevo limite</h3>
                </div>
                <form action="{{ route('admin.extensions.consumeservers.store') }}" method="POST">
                    @csrf
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-3">
                                <label>Servidor</label>
                                <select name="server_id" class="form-control" required>
                                    @foreach ($servers as $server)
                                        <option value="{{ $server->id }}">{{ $server->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Metrica</label>
                                <select name="metric" class="form-control" required>
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
@endsection
