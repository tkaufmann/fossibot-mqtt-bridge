
    /**
     * Get FossibotDeviceReader instance (lazy loading)
     */
    private function getReader()
    {
        require_once __DIR__ . '/libs/FossibotDeviceReader.php';
        return new FossibotDeviceReader();
    }

    /**
     * Phase 4: Get CommandQueue instance (lazy loading with caching)
     */
    private function getQueue()
    {
        if (self::$queueInstance === null) {
            require_once __DIR__ . '/libs/CommandQueue.php';
            self::$queueInstance = new CommandQueue(
                self::MQTT_SERVER_INSTANCE_ID,
                self::DEVICE_MAC,
                $this->InstanceID
            );
            IPS_LogMessage('FBLC', 'CommandQueue instance created');
        }
        return self::$queueInstance;
    }

    private function ProcessModbusPayload($binaryPayload)
    {
        $reader = $this->getReader();

        // Convert hex string to binary if needed
        if (ctype_xdigit($binaryPayload)) {
            $binaryPayload = hex2bin($binaryPayload);
        }

        if (!$reader->parsePayload($binaryPayload)) {
            IPS_LogMessage('FBLC', 'Failed to parse MODBUS payload');
            return;
        }

        // Update IP-Symcon variables with parsed values
        $this->UpdateVariables($reader);
    }

    private function UpdateVariables($reader)
    {
        // Update Real-Time Status (using camelCase idents)
        $this->SetValue('soc', $reader->getSoc());
        $this->SetValue('inputWatts', $reader->getInputWatts());
        $this->SetValue('outputWatts', $reader->getOutputWatts());
        $this->SetValue('dcInputWatts', $reader->getDcInputWatts());

        // Update Output States
        $this->SetValue('usbOutput', $reader->isUsbOutputOn());
        $this->SetValue('acOutput', $reader->isAcOutputOn());
        $this->SetValue('dcOutput', $reader->isDcOutputOn());
        $this->SetValue('ledOutput', $reader->isLedOutputOn());

        IPS_LogMessage('FBLC', sprintf('Updated: SoC=%.1f%%, Input=%dW, Output=%dW, AC=%s',
            $reader->getSoc(),
            $reader->getInputWatts(),
            $reader->getOutputWatts(),
            $reader->isAcOutputOn() ? 'ON' : 'OFF'
        ));
    }

    // === Phase 5: Public API (will be implemented next) ===

    /**
     * Phase 4: Test function to verify command queue works.
     * This will be replaced by proper control functions in Phase 5.
     */
    public function TestQueue()
    {
        require_once __DIR__ . '/libs/Commands/AcOutputCommand.php';

        $queue = $this->getQueue();

        // Queue AC ON command
        $queue->enqueue(AcOutputCommand::enable());

        // Start timer
        $this->SetTimerInterval('QueueTimer', 200);

        IPS_LogMessage('FBLC', 'Test command queued - check logs');
    }
}
