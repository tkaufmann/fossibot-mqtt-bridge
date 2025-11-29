<?php

// ABOUTME: Defines how commands respond via MQTT topics.

declare(strict_types=1);

enum CommandResponseType
{
    case IMMEDIATE;    // USB/DC/AC/LED -> immediate response on client/04
    case DELAYED;      // Settings -> only visible in periodic updates
    case READ_RESPONSE; // Register reads -> Response on client/data
}
