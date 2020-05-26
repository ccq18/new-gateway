<?php

class A
{
//    public $msg;
    public $b;

    public function __construct()
    {
        $this->b = new B();
    }

    function __set($name, $value)
    {
        $this->b->{$name} = $value;
    }

    function __get($name)
    {
        return $this->b->{$name} ;
    }
}

class B
{
    public $msg;
}

$a = new A();
var_dump($a->msg);
$a->msg = 123;

var_dump($a->msg);
var_dump($a->b->msg);