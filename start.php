<?php
/**
 * run with command
 * php start.php start
 */

ini_set('display_errors', 'on');

use NewGateway\Register;
use NewGateway\Gateway;

use Workerman\Worker;

if (strpos(strtolower(PHP_OS), 'win') === 0) {
    exit("start.php not support windows, please use start_for_win.bat\n");
}

// 检查扩展
if (!extension_loaded('pcntl')) {
    exit("Please install pcntl extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
}

if (!extension_loaded('posix')) {
    exit("Please install posix extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
}

// 标记是全局启动
define('GLOBAL_START', 1);
require_once __DIR__ . '/vendor/autoload.php';

////
//$worker = new Worker();
//$var1 = get_object_vars($worker);
//
////var_dump($vars);
//$businessWorker = new \NewGateway\BusinessWorker($worker,\Chat\Events::class);
//$register = new Register($worker);
//$gateway = new Gateway($worker);
//
//$var2 = get_object_vars($gateway);
//var_dump(
//    array_intersect(
//        array_keys($var1),
//        array_keys($var2)
//    )
//);
//
//exit();

// 加载所有Applications/*/start.php，以便启动所有服务
foreach (glob(__DIR__ . '/*/start*.php') as $start_file) {
    require_once $start_file;
}
// 运行所有服务
Worker::runAll();
