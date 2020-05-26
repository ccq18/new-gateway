<?php

namespace Workerman;
/**
 * @property $name;
 * @property $user;
 * @property $socket;
 * @property $status;
 * @property $reusePort;
 * @property $transport;
 * @property $count;
 * @property $reloadable;
 * @property $stopping;
 * @property $workerId;
 * @property $id;
 *
 * Emitted when worker processes start.*
 * @property callable $onWorkerStart = null;
 *
 * Emitted when a socket connection is successfully established.
 * @property callable $onConnect = null;
 *
 * Emitted when data is received.
 * @property callable $onMessage = null;
 *
 * Emitted when the other end of the socket sends a FIN packet.
 * @property callable $onClose = null;
 *
 * Emitted when an error occurs with connection.
 * @property callable $onError = null;
 *
 * Emitted when the send buffer becomes full.
 * @property callable $onBufferFull = null;
 *
 * Emitted when the send buffer becomes empty.
 * @property callable $onBufferDrain = null;
 *
 * Emitted when worker processes stoped.
 * @property callable $onWorkerStop = null;
 *
 * Emitted when worker processes get reload signal.
 * @property callable $onWorkerReload = null;
 */
class WorkerNew
{


    protected $worker;

    /**
     * Construct.
     *
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name = '', array $context_option = array())
    {
        $this->worker = new Worker($socket_name, $context_option);
    }

    public function __set($name, $value)
    {
        $this->worker->{$name} = $value;
    }

    function __get($name)
    {
        return $this->worker->{$name};
    }

    public function run()
    {
        $this->worker->run();
    }

    public function stop()
    {
        $this->worker->stop();
    }

    /**
     * Listen.
     *
     * @throws \Exception
     */
    public function listen()
    {
        $this->worker->listen();
    }


    public function getSocketName()
    {
        return $this->worker->getSocketName();
    }


    public function setUserAndGroup()
    {
        $this->worker->setUserAndGroup();
    }
}