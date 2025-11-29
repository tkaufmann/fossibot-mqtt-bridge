<?php

// ABOUTME: Defines how commands respond via MQTT topics.
// Converted from enum to class constants for PHP 7 compatibility.

declare(strict_types=1);

class CommandResponseType
{
    const IMMEDIATE = 'immediate';      // USB/DC/AC/LED -> immediate response on client/04
    const DELAYED = 'delayed';          // Settings -> only visible in periodic updates
    const READ_RESPONSE = 'read';       // Register reads -> Response on client/data
}
