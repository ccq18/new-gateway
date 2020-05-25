<?php

namespace GatewayWorker;


interface EventContract
{
    public static function onMessage($client_id, $message);
    public static function onClose($client_id);
    public static function onConnect($client_id);
    public static function onWebSocketConnect($client_id,$message);

}