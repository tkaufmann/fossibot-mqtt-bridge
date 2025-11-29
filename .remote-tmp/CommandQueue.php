<?php

// ABOUTME: Command queue with 200ms delay between MODBUS WRITE commands.
// Prevents device from being overwhelmed with rapid command sequences.

declare(strict_types=1);

class CommandQueue
{
    const QUEUE_DELAY_MS = 200;

    private $queue = [];
    private $processing = false;
    private $mqttServerId;
    private $deviceMac;
    private $moduleId;

    /**
     * @param int $mqttServerId IP-Symcon MQTT Server instance ID
     * @param string $deviceMac Device MAC address (e.g., "7C2C67AB5F0E")
     * @param int $moduleId Module instance ID for logging
     */
    public function __construct($mqttServerId, $deviceMac, $moduleId)
    {
        $this->mqttServerId = $mqttServerId;
        $this->deviceMac = $deviceMac;
        $this->moduleId = $moduleId;
    }

    /**
     * Add command to queue and start processing if not already running.
     */
    public function enqueue($command)
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
    public function getQueueSize()
    {
        return count($this->queue);
    }

    /**
     * Check if queue is currently processing.
     */
    public function isProcessing()
    {
        return $this->processing;
    }

    /**
     * Signal that queue processing should start.
     * Module will handle timer activation.
     */
    private function startProcessing()
    {
        $this->processing = true;
        IPS_LogMessage("FBLC", "Queue processing requested");
    }

    /**
     * Process next command in queue.
     * Called by module's ProcessQueue() function which is triggered by timer.
     *
     * @return bool True if more commands remain in queue
     */
    public function processNext()
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
    private function sendCommand($command)
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
