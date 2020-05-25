<?php


namespace Workerman;


use Workerman\Connection\ConnectionInterface;
use Workerman\Worker;

abstract class  WorkerAbstract
{
    protected $worker;

    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
        $worker->onWorkerStart = array($this, 'onWorkerStart');
        $worker->onConnect = array($this, 'onConnect');
        $worker->onMessage = array($this, 'onMessage');
        $worker->onClose = array($this, 'onClose');
        $worker->onWorkerStop = array($this, 'onWorkerStop');
        $worker->onWorkerReload = array($this, 'onWorkerReload');
        $worker->onError = array($this, 'onError');
        $worker->onBufferFull = array($this, 'onBufferFull');
        $worker->onBufferDrain = array($this, 'onBufferDrain');

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
     * @param ConnectionInterface $connection
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
     * @param ConnectionInterface $connection
     * @return void

     */
    public function onConnect($connection)
    {
    }

    /**
     * 当客户端关闭时
     *
     * @param ConnectionInterface $connection
     * @return void
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

    /** Emitted when worker processes get reload signal.
     * @param $worker
     * @return void
     */
    public function onWorkerReload($worker)
    {

    }



    /** Emitted when an error occurs with connection.
     * @param ConnectionInterface $connection
     * @param $code
     * @param $msg
     * @return void
     */
    public function onError($connection,$code, $msg)
    {

    }


    /**
     * Emitted when the send buffer becomes full.
     * @param $connection
     * @return void
     */
    public function onBufferFull($connection)
    {

    }

    /**
     * Emitted when the send buffer becomes empty.
     * @param ConnectionInterface $connection
     */
    public function onBufferDrain($connection)
    {

    }


}