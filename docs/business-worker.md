#  BusinessWorker.php 业务层

BusinessWorker不监听任何端口 
在启动时连接register网关
获取到gateway列表后尝试连接所有gateway进程

gatewayConnections 保存所有的gateway连接
onGatewayMessage
所有事件转发到 Events.php处理


## register部分
## events:
### broadcast_addresses 
处理gateway地址更新广播，尝试连接所有gateway
request:
``` 
{
"event":"broadcast_addresses",
"addresses":[""]
}
```

## gateway部分

CMD_ON_CONNECT CMD_ON_MESSAGE CMD_ON_CLOSE CMD_ON_WEBSOCKET_CONNECT
转发给 Event类处理：onConnect onMessage onClose onWebSocketConnect
CMD_PING 心跳