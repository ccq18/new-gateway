# Register.php 服务中心
负责所有服务的注册和通知
使用text协议提供服务 
（Workerman定义了一种叫做text的文本协议，协议格式为 数据包+换行符，即在每个数据包末尾加上一个换行符表示包的结束。见:http://doc.workerman.net/appendices/about-text.html）
传输数据结构
{event:eventName,otherData..}
原理：
1、Register、Gateway、BusinessWorker进程启动
2、Gateway、BusinessWorker进程启动后向Register服务进程发起长连接注册自己
3、Register服务收到Gateway的注册后，把所有Gateway的通讯地址保存在内存中
4、Register服务收到BusinessWorker的注册后，把内存中所有的Gateway的通讯地址发给BusinessWorker
5、BusinessWorker进程得到所有的Gateway内部通讯地址后尝试连接Gateway
6、至此Gateway与BusinessWorker通过Register已经建立起长连接

7、如果运行过程中有新的Gateway服务注册到Register（一般是分布式部署加机器），
则将新的Gateway内部通讯地址列表将广播给所有BusinessWorker，BusinessWorker收到后建立连接
8、如果有Gateway下线，则Register服务会收到通知，会将对应的内部通讯地址删除，
然后广播新的内部通讯地址列表给所有BusinessWorker，BusinessWorker不再连接下线的Gateway

9、客户端的事件及数据全部由Gateway转发给BusinessWorker处理，
BusinessWorker默认调用Events.php中的onConnect onMessage onClose处理业务逻辑。
10、BusinessWorker的业务逻辑入口全部在Events.php中，包括onWorkerStart进程启动事件(进程事件)、onConnect连接事件(客户端事件)、
onMessage消息事件（客户端事件）、onClose连接关闭事件（客户端事件）、onWorkerStop进程退出事件（进程事件）

因为业务代码只在BusinessWorker中 所以正常情况下发布代码只需要重启BusinessWorker的进程，
而BusinessWorker是无状态的，所以客户端连接并不会中断
## events：
### gateway_connect  
处理gateway注册,把所有Gateway的通讯地址保存在内存中,
把内存中所有的Gateway的通讯地址发给所有BusinessWorker
request:
```
{
"event":"gateway_connect",
"secret_key":"xxx",
"address":""
}
```
###  worker_connect 
处理BusinessWorker注册，把内存中所有的Gateway的通讯地址发给当前BusinessWorker
request:
``` 
{
"event":"worker_connect",
"secret_key":"xxx"
}
```

## reponses:
### broadcast_addresses 广播通知内部地址
变更和连接时 广播所有gateway
request:
``` 
{
"event":"broadcast_addresses",
"addresses":[""]
}
```

### ping 心跳 每间隔55秒以内发送一次
request:
``` 
{event:"ping"}
```

## onconnect
若连接10秒内没收到消息会超时关闭连接

## onclose gateway下线
将对应gateway的内部通讯地址删除，然后广播新的内部通讯地址列表给所有BusinessWorker，BusinessWorker不再连接下线的Gateway  