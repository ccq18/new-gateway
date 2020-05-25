<?php

namespace GatewayWorker\Business;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;

class BusinessRegister
{
    private $registerAddress, $secretKey, $businessWorker;

    /**
     * 用于保持长连接的心跳时间间隔
     *
     * @var int
     */
    const PERSISTENCE_CONNECTION_PING_INTERVAL = 25;

    /**
     * BusinessRegister constructor.
     * @param callable $onMessage
     * @param $registerAddress
     * @param $secretKey
     */
    public function __construct($onMessage, $registerAddress, $secretKey)
    {
        $this->onMessage = $onMessage;
        $this->registerAddress = $registerAddress;
        $this->secretKey = $secretKey;
    }

    /**
     * 连接服务注册中心
     *
     * @return void
     */
    public function connectToRegister()
    {
//        var_dump($this->registerAddress);
        foreach ($this->registerAddress as $register_address) {
            $register_connection = new AsyncTcpConnection("text://{$register_address}");
            $secret_key = $this->secretKey;
            $register_connection->onConnect = function () use ($register_connection, $secret_key, $register_address) {
                $register_connection->send('{"event":"worker_connect","secret_key":"' . $secret_key . '"}');
                // 如果Register服务器不在本地服务器，则需要保持心跳
                if (strpos($register_address, '127.0.0.1') !== 0) {
                    $register_connection->ping_timer = Timer::add(self::PERSISTENCE_CONNECTION_PING_INTERVAL, function () use ($register_connection) {
                        $register_connection->send('{"event":"ping"}');
                    });
                }
            };
            $register_connection->onClose = function ($register_connection) {
                /**
                 * @var AsyncTcpConnection $register_connection
                 */
                if (!empty($register_connection->ping_timer)) {
                    Timer::del($register_connection->ping_timer);
                }
                $register_connection->reconnect(1);
            };
            $register_connection->onMessage = $this->onMessage;
            $register_connection->connect();
        }
    }

}