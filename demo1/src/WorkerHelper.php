<?php

namespace GatewayWorker;


use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

class WorkerHelper
{

    /**
     * 随机路由，返回 worker connection 对象
     *
     * @param array         $worker_connections
     * @param TcpConnection $client_connection
     * @param int           $cmd
     * @param mixed         $buffer
     * @return TcpConnection
     */
    public static function routerRand($worker_connections, $client_connection, $cmd, $buffer)
    {
        return $worker_connections[array_rand($worker_connections)];
    }

    /**
     * client_id 与 worker 绑定
     *
     * @param array         $worker_connections
     * @param TcpConnection $client_connection
     * @param int           $cmd
     * @param mixed         $buffer
     * @return TcpConnection
     */
    public static function routerBind($worker_connections, $client_connection, $cmd, $buffer)
    {
        if (!isset($client_connection->businessworker_address) || !isset($worker_connections[$client_connection->businessworker_address])) {
            $client_connection->businessworker_address = array_rand($worker_connections);
        }
        return $worker_connections[$client_connection->businessworker_address];
    }

    /**
     * Log.
     * @param string $msg
     */
    public static function log($msg)
    {
        Timer::add(1, function () use ($msg) {
            Worker::log($msg);
        }, null, false);
    }
}