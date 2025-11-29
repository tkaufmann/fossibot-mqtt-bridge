<?php

// ABOUTME: CRC-16 Modbus checksum calculator for MQTT commands.

declare(strict_types=1);

class ModbusCrc
{
    /**
     * Calculate CRC-16 Modbus checksum.
     */
    public static function calculate(array $data): int
    {
        if (empty($data)) {
            throw new Exception('Data array cannot be empty');
        }

        foreach ($data as $index => $byte) {
            if (!is_int($byte) || $byte < 0 || $byte > 255) {
                throw new Exception("Invalid byte value at index {$index}: {$byte}. Must be integer 0-255");
            }
        }

        $crc = 0xFFFF;

        foreach ($data as $byte) {
            $crc ^= $byte;
            for ($i = 0; $i < 8; $i++) {
                if ($crc & 1) {
                    $crc = ($crc >> 1) ^ 0xA001; // Modbus polynomial
                } else {
                    $crc >>= 1;
                }
            }
        }

        return $crc & 0xFFFF;
    }

    /**
     * Append CRC to Modbus command.
     */
    public static function appendCrc(array $command): array
    {
        $crc = self::calculate($command);
        $crcHigh = ($crc >> 8) & 0xFF;
        $crcLow = $crc & 0xFF;

        // Append high byte first: [cmd...] + [crc_high, crc_low]
        return array_merge($command, [$crcHigh, $crcLow]);
    }
}
