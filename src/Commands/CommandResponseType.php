<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * Defines how commands respond via MQTT topics.
 *
 * Categorizes MQTT commands based on their response behavior patterns.
 * This determines how the system should wait for and handle responses
 * from the device after sending commands.
 */
enum CommandResponseType
{
    case IMMEDIATE;    // USB/DC/AC/LED -> sofortige Response auf client/04
    case DELAYED;      // Settings -> nur in periodic updates sichtbar
    case READ_RESPONSE; // Register reads -> Response auf client/data
}
