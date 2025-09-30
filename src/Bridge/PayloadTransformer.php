<?php
declare(strict_types=1);

namespace Fossibot\Bridge;

/**
 * Transforms MQTT payloads between Modbus binary and JSON.
 *
 * Modbus → JSON: Parse registers, build DeviceState, serialize JSON
 * JSON → Modbus: Parse command JSON, build Command, generate Modbus bytes
 */
class PayloadTransformer
{
    // TODO: Implementation in Phase 2
}