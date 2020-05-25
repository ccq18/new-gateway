<?php
namespace GatewayWorker\Business;


use GatewayWorker\BusinessWorker;

interface BusinessEventInterface
{
    public static function onWorkerStart(BusinessWorker $worker);

    public static function onWorkerReload(BusinessWorker $worker);

    public static function onWorkerStop(BusinessWorker $worker);

    public static function onConnect($client_id);

    public static function onWebSocketConnect($client_id, $message);

    public static function onMessage($client_id, $message);


    public static function onClose($client_id);
}