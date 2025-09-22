<?php

declare(strict_types=1);

namespace Fossibot\Contracts;

use Fossibot\Commands\Command;

/**
 * Interface for executing device commands.
 *
 * Defines the contract for any component that can execute commands
 * on IoT devices. This abstraction allows for different execution
 * strategies (queue-based, direct, mock, etc.) while maintaining
 * a consistent API.
 */
interface CommandExecutor
{
    /**
     * Execute a command on a specific device.
     *
     * @param string $deviceId Device identifier (MAC address without colons)
     * @param Command $command Command to execute
     * @throws \RuntimeException If command execution fails
     * @throws \InvalidArgumentException If device ID or command is invalid
     */
    public function execute(string $deviceId, Command $command): void;
}