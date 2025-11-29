<?php

// ABOUTME: MODBUS register type definitions.

declare(strict_types=1);

enum RegisterType: string
{
    case INPUT = 'input';    // FC 04 - realtime sensor data
    case HOLDING = 'holding'; // FC 03 - configuration settings
}
