<?php
require 'vendor/autoload.php';

use Fossibot\Bridge\TopicTranslator;

echo "Testing TopicTranslator...\n\n";

$translator = new TopicTranslator();

// Test 1: Cloud to Broker
echo "Test 1: cloudToBroker\n";
$cloudTopic = '7C2C67AB5F0E/device/response/client/04';
$brokerTopic = $translator->cloudToBroker($cloudTopic);
assert($brokerTopic === 'fossibot/7C2C67AB5F0E/state', "Expected 'fossibot/7C2C67AB5F0E/state', got '$brokerTopic'");
echo "✅ $cloudTopic\n   → $brokerTopic\n\n";

// Test 2: Broker to Cloud
echo "Test 2: brokerToCloud\n";
$brokerTopic = 'fossibot/7C2C67AB5F0E/command';
$cloudTopic = $translator->brokerToCloud($brokerTopic);
assert($cloudTopic === '7C2C67AB5F0E/client/request/data', "Expected '7C2C67AB5F0E/client/request/data', got '$cloudTopic'");
echo "✅ $brokerTopic\n   → $cloudTopic\n\n";

// Test 3: Extract MAC from cloud topic
echo "Test 3: extractMacFromCloudTopic\n";
$mac = $translator->extractMacFromCloudTopic('7c2c67ab5f0e/device/response/client/04');
assert($mac === '7C2C67AB5F0E', "Expected '7C2C67AB5F0E', got '$mac'");
echo "✅ Extracted MAC: $mac\n\n";

// Test 4: Extract MAC from broker topic
echo "Test 4: extractMacFromBrokerTopic\n";
$mac = $translator->extractMacFromBrokerTopic('fossibot/7C2C67AB5F0E/state');
assert($mac === '7C2C67AB5F0E', "Expected '7C2C67AB5F0E', got '$mac'");
echo "✅ Extracted MAC: $mac\n\n";

echo "✅ All TopicTranslator tests passed!\n";