<?php


namespace GatewayWorker;


use Workerman\Connection\TcpConnection;
use Workerman\Worker;

abstract class  WorkerAbstract
{
    protected $worker;

    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
        $worker->onWorkerStart = array($this, 'onWorkerStart');
        // 保存用户的回调，当对应的事件发生时触发
        $worker->onConnect = array($this, 'onConnect');

        // onMessage禁止用户设置回调
        $worker->onMessage = array($this, 'onMessage');

        // 保存用户的回调，当对应的事件发生时触发
        $worker->onClose = array($this, 'onClose');
        // 保存用户的回调，当对应的事件发生时触发
        $worker->onWorkerStop = array($this, 'onWorkerStop');
    }

    public function run()
    {
        $this->worker->run();
    }

    public function onWorkerStart()
    {

    }

    /**
     * 当 worker 发来数据时
     *
     * @param TcpConnection $connection
     * @param mixed $data
     * @throws \Exception
     *
     * @return void
     */
    public function onWorkerMessage($connection, $data)
    {

    }

    /**
     * 当客户端连接上来时，初始化一些客户端的数据
     * 包括全局唯一的client_id、初始化session等
     *
     * @param TcpConnection $connection
     */
    public function onConnect($connection)
    {
    }

    /**
     * 当客户端关闭时
     *
     * @param TcpConnection $connection
     */
    public function onClose($connection)
    {
    }

    /**
     * 当 gateway 关闭时触发，清理数据
     *
     * @return void
     */
    public function onWorkerStop()
    {
    }

    public function onWorkerReload($worker)
    {

    }
}