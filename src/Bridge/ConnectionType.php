<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

/**
 * Defines the type of connection for MQTT transport.
 *
 * Used to determine which transport strategy to use when establishing
 * the MQTT connection (WebSocket for cloud, TCP for local broker).
 */
enum ConnectionType
{
    case WEBSOCKET;
    case TCP;
}
