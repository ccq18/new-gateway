<?php

namespace GatewayWorker\WorkNew;


interface WorkContact
{
    public function onWorkerStop();

    public function onWorkerStart();

    public function onWorkerReload();

}