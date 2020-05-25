<?php

namespace GatewayWorker;


class Events implements EventContract
{

    public static function onMessage($client_id, $message)
    {
        // TODO: Implement onMessage() method.
    }

    public static function onClose($client_id)
    {
        // TODO: Implement onClose() method.
    }

    public static function onConnect($client_id)
    {
        // TODO: Implement onConnect() method.
    }

    public static function onWebSocketConnect($client_id, $message)
    {
        // TODO: Implement onWebSocketConnect() method.
    }
}