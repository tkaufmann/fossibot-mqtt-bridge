<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * Abstract base class for all MQTT Modbus commands.
 */
abstract class Command
{
    abstract public function getModbusBytes(): array;
    abstract public function getResponseType(): CommandResponseType;
    abstract public function getTargetRegister(): int;
    abstract public function getDescription(): string;
}