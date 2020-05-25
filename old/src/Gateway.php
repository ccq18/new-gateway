<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace GatewayWorker;

use GatewayWorker\Component\ClientEvent;
use GatewayWorker\Lib\Context;

use Workerman\Connection\TcpConnection;

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Autoloader;
use Workerman\Connection\AsyncTcpConnection;
use GatewayWorker\Protocols\GatewayProtocol;

/**
 *
 * Gateway，基于Worker 开发
 * 用于转发客户端的数据给Worker处理，以及转发Worker的数据给客户端
 *
 * @author walkor<walkor@workerman.net>
 *
 */
class Gateway extends Worker
{
    /**
     * 版本
     *
     * @var string
     */
    const VERSION = '3.0.15';

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
     * gateway进程转发给businessWorker进程的发送缓冲区大小
     *
     * @var int
     */
    public $sendToWorkerBufferSize = 10240000;

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
    protected $_clientConnections = array();

    /**
     * uid 到 connection 的映射，一对多关系
     */
    protected $_uidConnections = array();

    /**
     * group 到 connection 的映射，一对多关系
     *
     * @var array
     */
    protected $_groupConnections = array();



    /**
     * gateway 内部监听 worker 内部连接的 worker
     *
     * @var Worker
     */
    protected $_innerTcpWorker = null;

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

    use \GatewayWorker\Component\ClientEvent;
    use \GatewayWorker\Component\WorkerEvent;


    /**
     * 构造函数
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name, $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
		$this->_gatewayPort = substr(strrchr($socket_name,':'),1);
        $this->router = array("\\GatewayWorker\\Gateway", 'routerBind');

        $backtrace               = debug_backtrace();
        $this->_autoloadRootPath = dirname($backtrace[0]['file']);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart  = array($this, 'onWorkerStart');
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onConnect = $this->onConnect;
        $this->onConnect  = array($this, 'onClientConnect');

        // onMessage禁止用户设置回调
        $this->onMessage = array($this, 'onClientMessage');

        // 保存用户的回调，当对应的事件发生时触发
        $this->_onClose = $this->onClose;
        $this->onClose  = array($this, 'onClientClose');
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onWorkerStop = $this->onWorkerStop;
        $this->onWorkerStop  = array($this, 'onWorkerStop');

        if (!is_array($this->registerAddress)) {
            $this->registerAddress = array($this->registerAddress);
        }

        // 记录进程启动的时间
        $this->_startTime = time();
        // 运行父方法
        parent::run();
    }





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

        // 初始化 gateway 内部的监听，用于监听 worker 的连接已经连接上发来的数据
        $this->_innerTcpWorker = new Worker("GatewayProtocol://{$this->lanIp}:{$this->lanPort}");
        $this->_innerTcpWorker->listen();
	$this->_innerTcpWorker->name = 'GatewayInnerWorker';

        // 重新设置自动加载根目录
        Autoloader::setRootPath($this->_autoloadRootPath);

        // 设置内部监听的相关回调
        $this->_innerTcpWorker->onMessage = array($this, 'onWorkerMessage');

        $this->_innerTcpWorker->onConnect = array($this, 'onWorkerConnect');
        $this->_innerTcpWorker->onClose   = array($this, 'onWorkerClose');

        // 注册 gateway 的内部通讯地址，worker 去连这个地址，以便 gateway 与 worker 之间建立起 TCP 长连接
        $this->registerAddress();

        if ($this->_onWorkerStart) {
            call_user_func($this->_onWorkerStart, $this);
        }
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
            $register_connection->onConnect = function($register_connection) use ($address, $secret_key, $register_address){
                $register_connection->send('{"event":"gateway_connect", "address":"' . $address . '", "secret_key":"' . $secret_key . '"}');
                // 如果Register服务器不在本地服务器，则需要保持心跳
                if (strpos($register_address, '127.0.0.1') !== 0) {
                    $register_connection->ping_timer = Timer::add(self::PERSISTENCE_CONNECTION_PING_INTERVAL, function () use ($register_connection) {
                        $register_connection->send('{"event":"ping"}');
                    });
                }
            };
            $register_connection->onClose = function ($register_connection) {
                if(!empty($register_connection->ping_timer)) {
                    Timer::del($register_connection->ping_timer);
                }
                $register_connection->reconnect(1);
            };
            $register_connection->connect();
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

    /**
     * Log.
     * @param string $msg
     */
    public static function log($msg){
        Timer::add(1, function() use ($msg) {
            Worker::log($msg);
        }, null, false);
    }
}
