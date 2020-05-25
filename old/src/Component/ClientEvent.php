<?php
namespace GatewayWorker\Component;


use Workerman\Connection\TcpConnection;
use GatewayWorker\Protocols\GatewayProtocol;

trait ClientEvent
{

    /**
     * 当客户端发来数据时，转发给worker处理
     *
     * @param TcpConnection $connection
     * @param mixed         $data
     */
    public function onClientMessage($connection, $data)
    {
        $connection->pingNotResponseCount = -1;
        $this->sendToWorker(GatewayProtocol::CMD_ON_MESSAGE, $connection, $data);
    }

    /**
     * 当客户端连接上来时，初始化一些客户端的数据
     * 包括全局唯一的client_id、初始化session等
     *
     * @param TcpConnection $connection
     */
    public function onClientConnect($connection)
    {
        $connection->id = self::generateConnectionId();
        // 保存该连接的内部通讯的数据包报头，避免每次重新初始化
        $connection->gatewayHeader = array(
            'local_ip'      => ip2long($this->lanIp),
            'local_port'    => $this->lanPort,
            'client_ip'     => ip2long($connection->getRemoteIp()),
            'client_port'   => $connection->getRemotePort(),
            'gateway_port'  => $this->_gatewayPort,
            'connection_id' => $connection->id,
            'flag'          => 0,
        );
        // 连接的 session
        $connection->session                       = '';
        // 该连接的心跳参数
        $connection->pingNotResponseCount          = -1;
        // 该链接发送缓冲区大小
        $connection->maxSendBufferSize             = $this->sendToClientBufferSize;
        // 保存客户端连接 connection 对象
        $this->_clientConnections[$connection->id] = $connection;

        // 如果用户有自定义 onConnect 回调，则执行
        if ($this->_onConnect) {
            call_user_func($this->_onConnect, $connection);
        } elseif ($connection->protocol === '\Workerman\Protocols\Websocket') {
            $connection->onWebSocketConnect = array($this, 'onWebsocketConnect');
        }

        $this->sendToWorker(GatewayProtocol::CMD_ON_CONNECT, $connection);
    }

    /**
     * websocket握手时触发
     *
     * @param $connection
     * @param $http_buffer
     */
    public function onWebsocketConnect($connection, $http_buffer)
    {
        $this->sendToWorker(GatewayProtocol::CMD_ON_WEBSOCKET_CONNECT, $connection, array('get' => $_GET, 'server' => $_SERVER, 'cookie' => $_COOKIE));
    }


    /**
     * 当客户端关闭时
     *
     * @param TcpConnection $connection
     */
    public function onClientClose($connection)
    {
        // 尝试通知 worker，触发 Event::onClose
        $this->sendToWorker(GatewayProtocol::CMD_ON_CLOSE, $connection);
        unset($this->_clientConnections[$connection->id]);
        // 清理 uid 数据
        if (!empty($connection->uid)) {
            $uid = $connection->uid;
            unset($this->_uidConnections[$uid][$connection->id]);
            if (empty($this->_uidConnections[$uid])) {
                unset($this->_uidConnections[$uid]);
            }
        }
        // 清理 group 数据
        if (!empty($connection->groups)) {
            foreach ($connection->groups as $group) {
                unset($this->_groupConnections[$group][$connection->id]);
                if (empty($this->_groupConnections[$group])) {
                    unset($this->_groupConnections[$group]);
                }
            }
        }
        // 触发 onClose
        if ($this->_onClose) {
            call_user_func($this->_onClose, $connection);
        }
    }


    /**
     * 生成connection id
     * @return int
     */
    protected function generateConnectionId()
    {
        $max_unsigned_int = 4294967295;
        if (self::$_connectionIdRecorder >= $max_unsigned_int) {
            self::$_connectionIdRecorder = 0;
        }
        while(++self::$_connectionIdRecorder <= $max_unsigned_int) {
            if(!isset($this->_clientConnections[self::$_connectionIdRecorder])) {
                break;
            }
        }
        return self::$_connectionIdRecorder;
    }

    /**
     * 心跳逻辑
     *
     * @return void
     */
    public function ping()
    {
        $ping_data = $this->pingData ? (string)$this->pingData : null;
        $raw = false;
        if ($this->protocolAccelerate && $ping_data && $this->protocol) {
            $ping_data = $this->preEncodeForClient($ping_data);
            $raw = true;
        }
        // 遍历所有客户端连接
        foreach ($this->_clientConnections as $connection) {
            // 上次发送的心跳还没有回复次数大于限定值就断开
            if ($this->pingNotResponseLimit > 0 &&
                $connection->pingNotResponseCount >= $this->pingNotResponseLimit * 2
            ) {
                $connection->destroy();
                continue;
            }
            // $connection->pingNotResponseCount 为 -1 说明最近客户端有发来消息，则不给客户端发送心跳
            $connection->pingNotResponseCount++;
            if ($ping_data) {
                if ($connection->pingNotResponseCount === 0 ||
                    ($this->pingNotResponseLimit > 0 && $connection->pingNotResponseCount % 2 === 1)
                ) {
                    continue;
                }
                $connection->send($ping_data, $raw);
            }
        }
    }

    /**
     * @param mixed $data
     *
     * @return string
     */
    protected function preEncodeForClient($data)
    {
        foreach ($this->_clientConnections as $client_connection) {
            return call_user_func(array($client_connection->protocol, 'encode'), $data, $client_connection);
        }
    }

}