<?php

/**
 * ABOUTME: Value object representing Android device information for API requests.
 */

declare(strict_types=1);

namespace Fossibot\ValueObjects;

final readonly class DeviceInfo
{
    public function __construct(
        public string $platform = "app",
        public string $os = "android",
        public string $appId = "__UNI__55F5E7F",
        public string $deviceId = "",
        public string $channel = "google",
        public int $scene = 1001,
        public string $appName = "BrightEMS",
        public string $appVersion = "1.2.3",
        public string $deviceBrand = "Samsung",
        public string $deviceModel = "SM-A426B",
        public string $deviceType = "phone",
        public string $osName = "android",
        public int $osVersion = 10,
        public string $ua = "Mozilla/5.0 (Linux; Android 10; SM-A426B) AppleWebKit/537.36",
        public string $locale = "en"
    ) {
    }

    public function toArray(): array
    {
        return [
            'PLATFORM' => $this->platform,
            'OS' => $this->os,
            'APPID' => $this->appId,
            'DEVICEID' => $this->deviceId,
            'channel' => $this->channel,
            'scene' => $this->scene,
            'appName' => $this->appName,
            'appVersion' => $this->appVersion,
            'deviceBrand' => $this->deviceBrand,
            'deviceModel' => $this->deviceModel,
            'deviceType' => $this->deviceType,
            'osName' => $this->osName,
            'osVersion' => $this->osVersion,
            'ua' => $this->ua,
            'locale' => $this->locale,
        ];
    }
}
