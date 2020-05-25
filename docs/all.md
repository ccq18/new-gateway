Register.php
注册中心 负责协调gateway和worker

Gateway.php
网关，负责接受客户端请求会将信息随机发给一个worker处理

BusinessWorker.php
worker，业务工作进程，处理实际逻辑

Lib/Gateway.php
实现和gateway通信对工具类