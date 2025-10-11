<?php

declare(strict_types=1);

namespace Fossibot\Utils;

use InvalidArgumentException;

/**
 * CRC-16 Modbus checksum calculator for MQTT commands.
 */
class ModbusCrc
{
    /**
     * Calculate CRC-16 Modbus checksum.
     *
     * Implementation based on SYSTEM.md:
     * - Polynomial: 0xA001
     * - Initial: 0xFFFF
     *
     * @param array $data Array of bytes to calculate CRC for
     * @return int CRC-16 value
     * @throws \InvalidArgumentException If data is empty or contains invalid bytes
     */
    public static function calculate(array $data): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Data array cannot be empty');
        }

        foreach ($data as $index => $byte) {
            if (!is_int($byte) || $byte < 0 || $byte > 255) {
                $message = "Invalid byte value at index {$index}: {$byte}. Must be integer 0-255";
                throw new InvalidArgumentException($message);
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
     *
     * @param array $command Command bytes without CRC
     * @return array Command bytes with CRC appended (high byte first)
     * @throws \InvalidArgumentException If command is empty or contains invalid bytes
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
