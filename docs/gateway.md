## 启动时建立一个到register的连接
##  Gateway进程会监听一个websocket端口 用来处理客户端连接
onConnect 
新连接进来，按顺序生成一个connection_id
会转发到worker CMD_ON_WEBSOCKET_CONNECT 再执行 CMD_ON_CONNECT
onMessage 会转发到worker CMD_ON_MESSAGE 
onClose CMD_ON_CLOSE

当前用户的session 保存在连接的 gateway中

sendToWorker 随机取一个worker 转发进程到worker

client_id 保存来源gateway的 local_ip，local_port，connection_id，可以定位到客户端的连接

_clientConnections 保存了当前gateway的所有客户端连接
session 保存在当前客户端连接的 session属性中

_groupConnections  [group_id=>[connection_id=>Connection]]

_uidConnections   [uid=>[connection_id=>Connection]] 
向组发消息->所有gateway->对应组id的所有成员发送消息
## 获取所有session
取得所有gateway地址
向gateway发起请求并获得结果


## 每个Gateway进程会监听一个Gateway协议的端口 用来处理worker进程的请求

性能问题
sendToUid sendToGroup  ungroup sendToAll 需要所有gateway