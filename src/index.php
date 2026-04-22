<?php
// ملف: src/index.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ChatHandler.php';  // ← أضف هذا السطر

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use HaraSocial\ChatHandler;

echo "🚀 HARA SOCIAL WebSocket Server\n";
echo "📡 Running on ws://localhost:8080\n";
echo "⚠️  Press Ctrl+C to stop\n\n";

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ChatHandler()
        )
    ),
    8080
);

$server->run();
?>