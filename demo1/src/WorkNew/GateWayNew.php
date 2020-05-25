<?php

namespace GatewayWorker\WorkNew;

use GatewayWorker\Lib\Context;

use GatewayWorker\WorkerHelper;
use Workerman\Connection\TcpConnection;

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Autoloader;
use Workerman\Connection\AsyncTcpConnection;
use GatewayWorker\Protocols\GatewayProtocol;


class GateWayNew extends Worker
{


    /**
     * 版本
     *
     * @var string
     */
    const VERSION = '3.0.13';

    /**
     * 本机 IP
     *  单机部署默认 127.0.0.1，如果是分布式部署，需要设置成本机 IP
     *
     * @var string
     */
    public $lanIp = '127.0.0.1';

    /**
     * 本机端口
     *
     * @var string
     */
    public $lanPort = 0;

    /**
     * gateway 内部通讯起始端口，每个 gateway 实例应该都不同，步长1000
     *
     * @var int
     */
    public $startPort = 2000;

    /**
     * 注册服务地址,用于注册 Gateway BusinessWorker，使之能够通讯
     *
     * @var string|array
     */
    public $registerAddress = '127.0.0.1:1236';

    /**
     * 是否可以平滑重启，gateway 不能平滑重启，否则会导致连接断开
     *
     * @var bool
     */
    public $reloadable = false;

    /**
     * 心跳时间间隔
     *
     * @var int
     */
    public $pingInterval = 0;

    /**
     * $pingNotResponseLimit * $pingInterval 时间内，客户端未发送任何数据，断开客户端连接
     *
     * @var int
     */
    public $pingNotResponseLimit = 0;

    /**
     * 服务端向客户端发送的心跳数据
     *
     * @var string
     */
    public $pingData = '';

    /**
     * 秘钥
     *
     * @var string
     */
    public $secretKey = '';

    /**
     * 路由函数
     *
     * @var callback
     */
    public $router = null;



    /**
     * gateway进程将数据发给客户端时每个客户端发送缓冲区大小
     *
     * @var int
     */
    public $sendToClientBufferSize = 1024000;

    /**
     * 协议加速
     *
     * @var bool
     */
    public $protocolAccelerate = false;

    /**
     * 保存客户端的所有 connection 对象
     *
     * @var array
     */
    public $_clientConnections = array();

    /**
     * uid 到 connection 的映射，一对多关系
     */
    public $_uidConnections = array();

    /**
     * group 到 connection 的映射，一对多关系
     *
     * @var array
     */
    public $_groupConnections = array();

    /**
     * 保存所有 worker 的内部连接的 connection 对象
     *
     * @var array
     */
    public $_workerConnections = array();



    /**
     * 当 worker 启动时
     *
     * @var callback
     */
    protected $_onWorkerStart = null;

    /**
     * 当有客户端连接时
     *
     * @var callback
     */
    protected $_onConnect = null;

    /**
     * 当客户端发来消息时
     *
     * @var callback
     */
    protected $_onMessage = null;

    /**
     * 当客户端连接关闭时
     *
     * @var callback
     */
    protected $_onClose = null;

    /**
     * 当 worker 停止时
     *
     * @var callback
     */
    protected $_onWorkerStop = null;

    /**
     * 进程启动时间
     *
     * @var int
     */
    protected $_startTime = 0;

    /**
     * gateway 监听的端口
     *
     * @var int
     */
    protected $_gatewayPort = 0;

    /**
     * connectionId 记录器
     * @var int
     */
    protected static $_connectionIdRecorder = 0;

    /**
     * 用于保持长连接的心跳时间间隔
     *
     * @var int
     */
    const PERSISTENCE_CONNECTION_PING_INTERVAL = 25;

    /**
     * 构造函数
     *
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name, $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
        $this->_gatewayPort = substr(strrchr($socket_name, ':'), 1);
        $this->router = array("\\GatewayWorker\\Gateway", 'routerBind');

        $backtrace = debug_backtrace();
        $this->_autoloadRootPath = dirname($backtrace[0]['file']);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart = array($this, 'onWorkerStart');
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onConnect = $this->onConnect;
        $this->onConnect = array($this, 'onClientConnect');

        // onMessage禁止用户设置回调
        $this->onMessage = array($this, 'onClientMessage');

        // 保存用户的回调，当对应的事件发生时触发
        $this->_onClose = $this->onClose;
        $this->onClose = array($this, 'onClientClose');
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onWorkerStop = $this->onWorkerStop;
        $this->onWorkerStop = array($this, 'onWorkerStop');

        if (!is_array($this->registerAddress)) {
            $this->registerAddress = array($this->registerAddress);
        }

        // 记录进程启动的时间
        $this->_startTime = time();
        // 运行父方法
        parent::run();
    }

    /**
     * 当客户端发来数据时，转发给worker处理
     *
     * @param TcpConnection $connection
     * @param mixed $data
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
            'local_ip' => ip2long($this->lanIp),
            'local_port' => $this->lanPort,
            'client_ip' => ip2long($connection->getRemoteIp()),
            'client_port' => $connection->getRemotePort(),
            'gateway_port' => $this->_gatewayPort,
            'connection_id' => $connection->id,
            'flag' => 0,
        );
        // 连接的 session
        $connection->session = '';
        // 该连接的心跳参数
        $connection->pingNotResponseCount = -1;
        // 该链接发送缓冲区大小
        $connection->maxSendBufferSize = $this->sendToClientBufferSize;
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
     * 生成connection id
     * @return int
     */
    protected function generateConnectionId()
    {
        $max_unsigned_int = 4294967295;
        if (self::$_connectionIdRecorder >= $max_unsigned_int) {
            self::$_connectionIdRecorder = 0;
        }
        while (++self::$_connectionIdRecorder <= $max_unsigned_int) {
            if (!isset($this->_clientConnections[self::$_connectionIdRecorder])) {
                break;
            }
        }
        return self::$_connectionIdRecorder;
    }

    /**
     * 发送数据给 worker 进程
     *
     * @param int $cmd
     * @param TcpConnection $connection
     * @param mixed $body
     * @return bool
     */
    protected function sendToWorker($cmd, $connection, $body = '')
    {
        $gateway_data = $connection->gatewayHeader;
        $gateway_data['cmd'] = $cmd;
        $gateway_data['body'] = $body;
        $gateway_data['ext_data'] = $connection->session;
        if ($this->_workerConnections) {
            // 调用路由函数，选择一个worker把请求转发给它
            /** @var TcpConnection $worker_connection */
            $worker_connection = call_user_func($this->router, $this->_workerConnections, $connection, $cmd, $body);
            if (false === $worker_connection->send($gateway_data)) {
                $msg = "SendBufferToWorker fail. May be the send buffer are overflow. See http://doc2.workerman.net/send-buffer-overflow.html";
                WorkerHelper::log($msg);
                return false;
            }
        } // 没有可用的 worker
        else {
            // gateway 启动后 1-2 秒内 SendBufferToWorker fail 是正常现象，因为与 worker 的连接还没建立起来，
            // 所以不记录日志，只是关闭连接
            $time_diff = 2;
            if (time() - $this->_startTime >= $time_diff) {
                $msg = 'SendBufferToWorker fail. The connections between Gateway and BusinessWorker are not ready. See http://doc2.workerman.net/send-buffer-to-worker-fail.html';
                WorkerHelper::log($msg);
            }
            $connection->destroy();
            return false;
        }
        return true;
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
     * 当 Gateway 启动的时候触发的回调函数
     *
     * @return void
     */
    public function onWorkerStart()
    {
        // 分配一个内部通讯端口
        $this->lanPort = $this->startPort + $this->id;

        // 如果有设置心跳，则定时执行
        if ($this->pingInterval > 0) {
            $timer_interval = $this->pingNotResponseLimit > 0 ? $this->pingInterval / 2 : $this->pingInterval;
            Timer::add($timer_interval, array($this, 'ping'));
        }

        // 如果BusinessWorker ip不是127.0.0.1，则需要加gateway到BusinessWorker的心跳
        if ($this->lanIp !== '127.0.0.1') {
            Timer::add(self::PERSISTENCE_CONNECTION_PING_INTERVAL, array($this, 'pingBusinessWorker'));
        }

        if (!class_exists('\Protocols\GatewayProtocol')) {
            class_alias('GatewayWorker\Protocols\GatewayProtocol', 'Protocols\GatewayProtocol');
        }



        // 重新设置自动加载根目录
        Autoloader::setRootPath($this->_autoloadRootPath);
        /**
         * 处理gateway 内部连接
         */
        $this->connect();



        // 注册 gateway 的内部通讯地址，worker 去连这个地址，以便 gateway 与 worker 之间建立起 TCP 长连接
        $this->registerAddress();

        if ($this->_onWorkerStart) {
            call_user_func($this->_onWorkerStart, $this);
        }
    }



    public function connect(){

        /**
         * gateway 内部监听 worker 内部连接的 worker
         *
         * @var Worker
         */
        $_innerTcpWorker = new Worker("GatewayProtocol://{$this->lanIp}:{$this->lanPort}");
        $_innerTcpWorker->listen();
        $_innerTcpWorker->name = 'GatewayInnerWorker';
        $workconn = new WorkConn($this);
        // 设置内部监听的相关回调
        $_innerTcpWorker->onMessage = array($workconn, 'onWorkerMessage');
        $_innerTcpWorker->onConnect = array($workconn, 'onWorkerConnect');
        $_innerTcpWorker->onClose = array($workconn, 'onWorkerClose');
    }


    /**
     * 存储当前 Gateway 的内部通信地址
     *
     * @return bool
     */
    public function registerAddress()
    {
        $address = $this->lanIp . ':' . $this->lanPort;
        foreach ($this->registerAddress as $register_address) {
            $register_connection = new AsyncTcpConnection("text://{$register_address}");
            $secret_key = $this->secretKey;
            $register_connection->onConnect = function ($register_connection) use ($address, $secret_key, $register_address) {
                $register_connection->send('{"event":"gateway_connect", "address":"' . $address . '", "secret_key":"' . $secret_key . '"}');
                // 如果Register服务器不在本地服务器，则需要保持心跳
                if (strpos($register_address, '127.0.0.1') !== 0) {
                    $register_connection->ping_timer = Timer::add(self::PERSISTENCE_CONNECTION_PING_INTERVAL, function () use ($register_connection) {
                        $register_connection->send('{"event":"ping"}');
                    });
                }
            };
            $register_connection->onClose = function ($register_connection) {
                if (!empty($register_connection->ping_timer)) {
                    Timer::del($register_connection->ping_timer);
                }
                $register_connection->reconnect(1);
            };
            $register_connection->connect();
        }
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
     * 向 BusinessWorker 发送心跳数据，用于保持长连接
     *
     * @return void
     */
    public function pingBusinessWorker()
    {
        $gateway_data = GatewayProtocol::$empty;
        $gateway_data['cmd'] = GatewayProtocol::CMD_PING;
        foreach ($this->_workerConnections as $connection) {
            $connection->send($gateway_data);
        }
    }

    /**
     * @param mixed $data
     *
     * @return string
     */
    public function preEncodeForClient($data)
    {
        foreach ($this->_clientConnections as $client_connection) {
            return call_user_func(array($client_connection->protocol, 'encode'), $data, $client_connection);
        }
    }

    /**
     * 当 gateway 关闭时触发，清理数据
     *
     * @return void
     */
    public function onWorkerStop()
    {
        // 尝试触发用户设置的回调
        if ($this->_onWorkerStop) {
            call_user_func($this->_onWorkerStop, $this);
        }

    }

}