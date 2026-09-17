<?php

namespace Pterodactyl\Extensions\ConsumeServers\Services;

/**
 * Cliente minimo del protocolo "Server List Ping" de Minecraft (Java
 * Edition) — https://wiki.vg/Server_List_Ping — para leer jugadores
 * conectados sin depender de RCON ni de nada que haya que configurar en el
 * servidor: es el mismo paquete que manda el cliente del juego para pintar
 * el servidor en la lista de multijugador.
 */
class MinecraftPingService
{
    /**
     * @return array{online: bool, players_online: int, players_max: int, version: string|null, motd: string|null}|null
     *              null si no se pudo conectar o la respuesta no es valida (no es un servidor de Minecraft, esta apagado, etc.)
     */
    public function ping(string $host, int $port, float $timeout = 1.5): ?array
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$socket) {
            return null;
        }

        try {
            stream_set_timeout($socket, (int) ceil($timeout));

            $handshake = $this->packVarInt(0x00)
                . $this->packVarInt(-1)
                . $this->packString($host)
                . pack('n', $port)
                . $this->packVarInt(1);

            fwrite($socket, $this->packVarInt(strlen($handshake)) . $handshake);
            fwrite($socket, $this->packVarInt(1) . $this->packVarInt(0x00));

            $this->readVarInt($socket); // longitud total del paquete de respuesta (no la necesitamos)
            $packetId = $this->readVarInt($socket);

            if ($packetId !== 0x00) {
                return null;
            }

            $jsonLength = $this->readVarInt($socket);
            $json = $this->readExact($socket, $jsonLength);
            $data = json_decode($json, true);

            if (!is_array($data)) {
                return null;
            }

            return [
                'online' => true,
                'players_online' => (int) ($data['players']['online'] ?? 0),
                'players_max' => (int) ($data['players']['max'] ?? 0),
                'version' => $data['version']['name'] ?? null,
                'motd' => is_array($data['description'] ?? null)
                    ? ($data['description']['text'] ?? null)
                    : ($data['description'] ?? null),
            ];
        } catch (\Throwable $exception) {
            return null;
        } finally {
            fclose($socket);
        }
    }

    protected function packVarInt(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $bytes = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            if ($value !== 0) {
                $byte |= 0x80;
            }
            $bytes .= chr($byte);
        } while ($value !== 0);

        return $bytes;
    }

    protected function packString(string $value): string
    {
        return $this->packVarInt(strlen($value)) . $value;
    }

    /**
     * @param resource $socket
     */
    protected function readVarInt($socket): int
    {
        $result = 0;
        $shift = 0;

        for ($i = 0; $i < 5; $i++) {
            $byte = $this->readExact($socket, 1);

            if ($byte === '') {
                throw new \RuntimeException('Conexion cerrada mientras se leia un VarInt.');
            }

            $ord = ord($byte);
            $result |= ($ord & 0x7F) << $shift;

            if (($ord & 0x80) === 0) {
                return $result;
            }

            $shift += 7;
        }

        throw new \RuntimeException('VarInt demasiado largo (respuesta invalida).');
    }

    /**
     * @param resource $socket
     */
    protected function readExact($socket, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out']) || feof($socket)) {
                    throw new \RuntimeException('Se agoto el tiempo de espera leyendo la respuesta.');
                }
                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }
}
