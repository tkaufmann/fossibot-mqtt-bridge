<?php
require 'vendor/autoload.php';

use React\EventLoop\Loop;

echo "Testing ReactPHP installation...\n";

$loop = Loop::get();

$loop->addTimer(1.0, function() {
    echo "✅ Timer executed after 1 second\n";
    echo "✅ ReactPHP event loop is working!\n";
});

echo "Starting event loop...\n";
$loop->run();