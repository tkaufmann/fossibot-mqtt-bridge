<?php

declare(strict_types=1);

namespace Fossibot\ValueObjects;

/**
 * Represents the current authentication state of a Connection.
 */
enum AuthState
{
    case DISCONNECTED;
    case STAGE1_IN_PROGRESS;
    case STAGE1_COMPLETED;
    case STAGE2_IN_PROGRESS;
    case STAGE2_COMPLETED;
    case STAGE3_IN_PROGRESS;
    case STAGE3_COMPLETED;
    case STAGE4_IN_PROGRESS;
    case FULLY_CONNECTED;
    case FAILED;
}
