<?php

namespace GatewayWorker\WorkNew;

use Workerman\Worker;

class WorkRuner extends Worker
{
    protected $work;

    public function __construct(WorkContact $work, $name=null,$count=4)
    {
        $this->name = $name;
        $this->count=$count;
        $this->work = $work;
        $this->onWorkerStop = array($this->work , 'onWorkerStop');
        $this->onWorkerStart = array($this->work , 'onWorkerStart');
        $this->onWorkerReload = array($this->work , 'onWorkerReload');
    }
}