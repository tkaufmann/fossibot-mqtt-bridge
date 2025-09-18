<?php

declare(strict_types=1);

namespace Fossibot\Commands;

/**
 * Defines how commands respond via MQTT topics.
 */
enum CommandResponseType
{
    case IMMEDIATE;    // USB/DC/AC/LED -> sofortige Response auf client/04
    case DELAYED;      // Settings -> nur in periodic updates sichtbar
    case READ_RESPONSE; // Register reads -> Response auf client/data
}