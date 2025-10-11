<?php

declare(strict_types=1);

namespace Fossibot\Commands;

use InvalidArgumentException;

/**
 * Command to set AC charging upper limit on F2400 device.
 *
 * Controls the maximum battery level to which the device will charge
 * when using AC input. Useful for extending battery life by avoiding
 * 100% charges. Valid range: 0.0%-100.0% (0-1000 tenths). Register 67.
 */
class AcChargingUpperLimitCommand extends WriteRegisterCommand
{
    private const TARGET_REGISTER = 67;
    private const MIN_VALUE = 0;    // 0.0%
    private const MAX_VALUE = 1000; // 100.0%

    /**
     * Create command to set AC charging upper limit.
     *
     * @param int $limitTenths Charging limit in tenths (0-1000, where 800 = 80.0%)
     * @throws \InvalidArgumentException If limit is outside valid range
     */
    public function __construct(private readonly int $limitTenths)
    {
        if ($limitTenths < self::MIN_VALUE || $limitTenths > self::MAX_VALUE) {
            $message = "AC charge limit must be " . self::MIN_VALUE . "-" . self::MAX_VALUE .
                " tenths (0.0%-100.0%). Got: {$limitTenths}";
            throw new InvalidArgumentException($message);
        }

        parent::__construct(self::TARGET_REGISTER, $limitTenths, CommandResponseType::DELAYED);
    }

    /**
     * Create command for specific percentage value.
     *
     * @param float $percentage Charging limit as percentage (0.0-100.0)
     * @return self Command instance
     * @throws \InvalidArgumentException If percentage is outside valid range
     */
    public static function setLimit(float $percentage): self
    {
        if ($percentage < 0.0 || $percentage > 100.0) {
            throw new InvalidArgumentException(
                "AC charge limit percentage must be 0.0-100.0%. Got: {$percentage}"
            );
        }

        $tenths = (int)round($percentage * 10);
        return new self($tenths);
    }

    /**
     * Get the charging limit value in tenths.
     *
     * @return int Limit in tenths (0-1000)
     */
    public function getLimitTenths(): int
    {
        return $this->limitTenths;
    }

    /**
     * Get the charging limit as percentage.
     *
     * @return float Limit as percentage (0.0-100.0)
     */
    public function getLimitPercentage(): float
    {
        return $this->limitTenths / 10.0;
    }

    public function getDescription(): string
    {
        $percentage = $this->getLimitPercentage();
        return "Set AC charging upper limit to {$percentage}%";
    }
}
