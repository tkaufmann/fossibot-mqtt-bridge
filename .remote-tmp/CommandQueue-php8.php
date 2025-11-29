<?php

// ABOUTME: Command queue with 200ms delay between MODBUS WRITE commands.
// Prevents device from being overwhelmed with rapid command sequences.

declare(strict_types=1);

class CommandQueue
{
    private const QUEUE_DELAY_MS = 200;

    /** @var Command[] */
    private array $queue = [];
    private bool $processing = false;

    public function __construct(
        private readonly int $mqttServerId,
        private readonly string $deviceMac,
        private readonly int $moduleId
    ) {
    }

    /**
     * Add command to queue and start processing if not already running.
     */
    public function enqueue(Command $command): void
    {
        $this->queue[] = $command;

        IPS_LogMessage(
            "FBLC",
            "Command queued: {$command->getDescription()} (Queue size: " . count($this->queue) . ")"
        );

        // Start processing if queue was empty
        if (!$this->processing) {
            $this->startProcessing();
        }
    }

    /**
     * Get current queue size.
     */
    public function getQueueSize(): int
    {
        return count($this->queue);
    }

    /**
     * Check if queue is currently processing.
     */
    public function isProcessing(): bool
    {
        return $this->processing;
    }

    /**
     * Signal that queue processing should start.
     */
    private function startProcessing(): void
    {
        $this->processing = true;
        IPS_LogMessage("FBLC", "Queue processing requested");
    }

    /**
     * Process next command in queue.
     * Called by module's ProcessQueue() function which is triggered by timer.
     */
    public function processNext(): bool
    {
        if (empty($this->queue)) {
            $this->processing = false;
            IPS_LogMessage("FBLC", "Queue empty, stopping processing");
            return false;
        }

        $command = array_shift($this->queue);

        try {
            $this->sendCommand($command);
            IPS_LogMessage(
                "FBLC",
                "Command sent: {$command->getDescription()} (Remaining: " . count($this->queue) . ")"
            );
        } catch (Exception $e) {
            IPS_LogMessage("FBLC", "ERROR sending command: " . $e->getMessage());
        }

        // Return true if more commands remain
        $hasMore = !empty($this->queue);
        if (!$hasMore) {
            $this->processing = false;
            IPS_LogMessage("FBLC", "All commands processed");
        }

        return $hasMore;
    }

    /**
     * Send MODBUS command via MQTT.
     */
    private function sendCommand(Command $command): void
    {
        $bytes = $command->getModbusBytes();

        // Convert bytes to binary string
        $binaryPayload = '';
        foreach ($bytes as $byte) {
            $binaryPayload .= chr($byte);
        }

        // MQTT topic: {MAC}/client/request/data
        $topic = $this->deviceMac . '/client/request/data';

        // Publish via MQTT Server
        MQTT_Publish($this->mqttServerId, $topic, $binaryPayload);

        IPS_LogMessage(
            "FBLC",
            "MQTT published: topic={$topic}, bytes=" . bin2hex($binaryPayload)
        );
    }
}
