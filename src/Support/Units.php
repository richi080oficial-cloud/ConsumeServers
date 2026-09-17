<?php

namespace Pterodactyl\Extensions\ConsumeServers\Support;

/**
 * Formatea los valores crudos que guarda/calcula la extension (siempre en
 * MB para memoria/red, minutos para uptime, porcentaje entero para cpu) en
 * texto legible para el admin, mostrando GB cuando el valor es grande.
 */
class Units
{
    /**
     * Hasta cuanto CPU absoluto (sin limite asignado en el servidor) se
     * considera "barra llena". Wings reporta el uso por nucleo (un proceso
     * usando 3 nucleos completos marca ~300%), asi que llenar la barra ya
     * al 100% hacia que casi todo se viera en rojo desde el principio. Con
     * este techo, la barra sube gradualmente y solo se ve llena de verdad
     * cuando el servidor esta usando el equivalente a 5 nucleos completos.
     */
    public const CPU_ABSOLUTE_BAR_MAX = 500;

    /**
     * Porcentaje (0-100) que debe ocupar la barra de progreso para un valor
     * de CPU. Si el servidor tiene un limite de CPU asignado se usa el
     * porcentaje relativo a ese limite (ya viene 0-100); si no, se escala
     * el valor absoluto contra CPU_ABSOLUTE_BAR_MAX en vez de contra 100.
     */
    public static function cpuBarPercent(int $value, ?int $cpuRelative): int
    {
        $percent = $cpuRelative ?? (int) round(($value / self::CPU_ABSOLUTE_BAR_MAX) * 100);

        return min(100, max(0, $percent));
    }

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
