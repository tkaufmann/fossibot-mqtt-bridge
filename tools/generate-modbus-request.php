#!/usr/bin/env php
<?php

declare(strict_types=1);

// ABOUTME: Helper script to generate MODBUS request payloads.
// Outputs binary MODBUS frames as hex strings for use with MQTT.

require_once __DIR__ . '/../vendor/autoload.php';

use Fossibot\Commands\ReadHoldingRegistersCommand;
use Fossibot\Commands\ReadRegistersCommand;
use Fossibot\Commands\ScreenRestTimeCommand;
use Fossibot\Bridge\PayloadTransformer;

if ($argc < 2) {
    echo "Usage: {$argv[0]} <command> [value]\n";
    echo "\n";
    echo "Commands:\n";
    echo "  fc03              - Read Holding Registers (FC 03, settings)\n";
    echo "  fc04              - Read Input Registers (FC 04, realtime data)\n";
    echo "  screen <seconds>  - Write Screen Rest Time (FC 06)\n";
    echo "                      Valid values: 0, 180, 300, 600, 1800\n";
    echo "\n";
    exit(1);
}

$commandType = strtolower($argv[1]);

$command = match ($commandType) {
    'fc03' => ReadHoldingRegistersCommand::create(),
    'fc04' => ReadRegistersCommand::create(),
    'screen' => new ScreenRestTimeCommand((int)($argv[2] ?? throw new InvalidArgumentException('Missing seconds parameter'))),
    default => throw new InvalidArgumentException("Unknown command: {$commandType}")
};

// Get MODBUS bytes
$bytes = $command->getModbusBytes();

// Convert to hex string
$hex = '';
foreach ($bytes as $byte) {
    $hex .= sprintf('%02x', $byte);
}

echo $hex;
