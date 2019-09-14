<?php
namespace GatewayWorker;

class SwitchCase{
    protected $cases=[];
    protected $_parseToParams = null;
    public function addCase($case,$runer){
        $this->cases[$case] = $runer;
    }

    public function run($value,$data){
        if (isset($this->cases[$value])) {
            return call_user_func_array($this->cases[$value], $data);
        }
    }
}