<?php

// ABOUTME: Command queue with 200ms delay between MODBUS WRITE commands.
// Uses IPS_SetBuffer/GetBuffer for persistence across API calls.

declare(strict_types=1);

require_once __DIR__ . '/mqtt_helper.php';

class CommandQueue
{
    private const QUEUE_DELAY_MS = 200;

    public function __construct(
        private readonly int $mqttServerId,
        private readonly string $deviceMac,
        private readonly int $moduleId,
        private readonly \Closure $getBufferFn,
        private readonly \Closure $setBufferFn
    ) {
    }

    /**
     * Add command to queue.
     * First command is sent immediately, subsequent commands via timer.
     */
    public function enqueue(Command $command): void
    {
        // Load queue from buffer
        $queue = $this->loadQueue();

        // Serialize command as array (objects can't be buffered)
        $cmdData = [
            'description' => $command->getDescription(),
            'bytes' => $command->getModbusBytes(),
            'timestamp' => microtime(true)
        ];

        $queue[] = $cmdData;

        IPS_LogMessage(
            "FBLC",
            "Command queued: {$cmdData['description']} (Queue size: " . count($queue) . ")"
        );

        // Save queue
        $this->saveQueue($queue);

        // If this is the first command, send immediately
        if (count($queue) === 1) {
            $this->processNext();
        }
        // Otherwise timer will handle it
    }

    /**
     * Process next command in queue.
     * Called by module's ProcessQueue() timer callback.
     */
    public function processNext(): bool
    {
        $queue = $this->loadQueue();

        if (empty($queue)) {
            IPS_LogMessage("FBLC", "Queue empty, stopping processing");
            return false;
        }

        // Get first command
        $cmdData = array_shift($queue);

        try {
            $this->sendCommand($cmdData);
            IPS_LogMessage(
                "FBLC",
                "Command sent: {$cmdData['description']} (Remaining: " . count($queue) . ")"
            );
        } catch (Exception $e) {
            IPS_LogMessage("FBLC", "ERROR sending command: " . $e->getMessage());
        }

        // Save updated queue
        $this->saveQueue($queue);

        // Return true if more commands remain
        return !empty($queue);
    }

    /**
     * Get current queue size.
     */
    public function getQueueSize(): int
    {
        return count($this->loadQueue());
    }

    /**
     * Send MODBUS command via MQTT.
     */
    private function sendCommand(array $cmdData): void
    {
        $bytes = $cmdData['bytes'];

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

    /**
     * Load queue from IPS buffer.
     */
    private function loadQueue(): array
    {
        $bufferData = ($this->getBufferFn)('CommandQueue');

        if (empty($bufferData)) {
            return [];
        }

        $queue = json_decode($bufferData, true);
        return is_array($queue) ? $queue : [];
    }

    /**
     * Save queue to IPS buffer.
     */
    private function saveQueue(array $queue): void
    {
        $bufferData = json_encode($queue);
        ($this->setBufferFn)('CommandQueue', $bufferData);
    }
}
