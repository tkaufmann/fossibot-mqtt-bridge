<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

use InvalidArgumentException;

/**
 * Translates MQTT topics between Fossibot Cloud and local broker.
 *
 * Cloud topics: {mac}/device/response/client/04
 * Broker topics: fossibot/{mac}/state
 */
class TopicTranslator
{
    /**
     * Convert cloud topic to broker topic.
     *
     * Examples:
     * - "7C2C67AB5F0E/device/response/client/04" → "fossibot/7C2C67AB5F0E/state"
     * - "7C2C67AB5F0E/device/response/client/data" → "fossibot/7C2C67AB5F0E/state"
     *
     * @param string $cloudTopic Fossibot Cloud topic
     * @return string Standard MQTT topic
     */
    public function cloudToBroker(string $cloudTopic): string
    {
        $mac = $this->extractMacFromCloudTopic($cloudTopic);

        if ($mac === null) {
            throw new InvalidArgumentException("Cannot extract MAC from cloud topic: $cloudTopic");
        }

        // All device response topics → state topic
        if (str_contains($cloudTopic, '/device/response/')) {
            return "fossibot/$mac/state";
        }

        // Unknown pattern
        return "fossibot/$mac/unknown";
    }

    /**
     * Convert broker topic to cloud topic.
     *
     * Example:
     * - "fossibot/7C2C67AB5F0E/command" → "7C2C67AB5F0E/client/request/data"
     *
     * @param string $brokerTopic Standard MQTT topic
     * @return string Fossibot Cloud topic
     */
    public function brokerToCloud(string $brokerTopic): string
    {
        $mac = $this->extractMacFromBrokerTopic($brokerTopic);

        if ($mac === null) {
            throw new InvalidArgumentException("Cannot extract MAC from broker topic: $brokerTopic");
        }

        // Commands → client request topic
        if (str_contains($brokerTopic, '/command')) {
            return "$mac/client/request/data";
        }

        throw new InvalidArgumentException("Unknown broker topic pattern: $brokerTopic");
    }

    /**
     * Extract MAC address from cloud topic.
     *
     * @param string $topic Cloud topic (e.g., "7C2C67AB5F0E/device/response/client/04")
     * @return string|null MAC address or null if not found
     */
    public function extractMacFromCloudTopic(string $topic): ?string
    {
        // MAC is first segment before /
        $parts = explode('/', $topic);

        if (empty($parts[0])) {
            return null;
        }

        $mac = $parts[0];

        // Validate MAC format (12 hex chars)
        if (strlen($mac) === 12 && ctype_xdigit($mac)) {
            return strtoupper($mac);
        }

        return null;
    }

    /**
     * Extract MAC address from broker topic.
     *
     * @param string $topic Broker topic (e.g., "fossibot/7C2C67AB5F0E/state")
     * @return string|null MAC address or null if not found
     */
    public function extractMacFromBrokerTopic(string $topic): ?string
    {
        // Pattern: fossibot/{mac}/...
        if (preg_match('/^fossibot\/([A-F0-9]{12})\//i', $topic, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }
}
