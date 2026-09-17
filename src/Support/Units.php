<?php

namespace Pterodactyl\Extensions\ConsumeServers\Support;

/**
 * Formatea los valores crudos que guarda/calcula la extension (siempre en
 * MB para memoria/red, minutos para uptime, porcentaje entero para cpu) en
 * texto legible para el admin, mostrando GB cuando el valor es grande.
 */
class Units
{
    public static function humanize(string $metric, int $value): string
    {
        return match ($metric) {
            'cpu' => $value . '%',
            'memory', 'network' => self::megabytes($value),
            'uptime' => self::minutes($value),
            default => (string) $value,
        };
    }

    /**
     * "512 MB" por debajo de 1 GB, "2.50 GB" a partir de ahi.
     */
    public static function megabytes(int $mb): string
    {
        if ($mb >= 1024) {
            return number_format($mb / 1024, 2) . ' GB';
        }

        return $mb . ' MB';
    }

    /**
     * "45 min", "3h 20m" o "2d 5h" segun la magnitud.
     */
    public static function minutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        if ($minutes < 1440) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            return $rest > 0 ? "{$hours}h {$rest}m" : "{$hours}h";
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
    }

    /**
     * Unidad corta usada en los <select> y encabezados de tabla.
     */
    public static function shortUnit(string $metric): string
    {
        return match ($metric) {
            'cpu' => '%',
            'memory', 'network' => 'MB',
            'uptime' => 'min',
            default => '',
        };
    }

    /**
     * Iconos de Font Awesome 4 (el que trae AdminLTE en Pterodactyl 1.x).
     */
    public static function icon(string $metric): string
    {
        return match ($metric) {
            'cpu' => 'fa-tachometer',
            'memory' => 'fa-hdd-o',
            'network' => 'fa-exchange',
            'uptime' => 'fa-clock-o',
            default => 'fa-question',
        };
    }

    public static function label(string $metric): string
    {
        return match ($metric) {
            'cpu' => 'CPU',
            'memory' => 'Memoria',
            'network' => 'Red',
            'uptime' => 'Uptime',
            default => ucfirst($metric),
        };
    }
}
