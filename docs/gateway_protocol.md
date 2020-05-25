# Gateway 与 Worker 间通讯的二进制协议
 ```
  struct GatewayProtocol
  {
      unsigned int        pack_len,
      unsigned char       cmd,//命令字
      unsigned int        local_ip,//gateway内网ip
      unsigned short      local_port,//gateway监听worker端口
      unsigned int        client_ip,//客户端连接端口
      unsigned short      client_port,
      unsigned int        connection_id,
      unsigned char       flag,
      unsigned short      gateway_port,//gateway监听客户端口
      unsigned int        ext_len,
      char[ext_len]       ext_data,//扩展数据长度
      char[pack_length-HEAD_LEN] body//包体
  }
```

## cmd 命令列表
### 由BusinessWorker 处理
* 发给worker，gateway有一个新的连接
 CMD_ON_CONNECT = 1;

* 发给worker的，客户端有消息
 CMD_ON_MESSAGE = 3;

* 发给worker上的关闭链接事件
 CMD_ON_CLOSE = 4;

* 当websocket握手时触发，只有websocket协议支持此命令字
 CMD_ON_WEBSOCKET_CONNECT = 205;
* 心跳
 CMD_PING = 201;
 
 
### 由Gateway处理
 

#### session



* 发给gateway，通知用户session更新 Gateway::updateSession($client_id, array $session)
CMD_UPDATE_SESSION = 9;

* 获取在线状态 Gateway::getAllClientSessions($group = '')
CMD_GET_ALL_CLIENT_SESSIONS = 10;

* 获取组成员 Gateway::getClientSessionsByGroup($group);
 CMD_GET_CLIENT_SESSIONS_BY_GROUP = 23;
 
 * 根据client_id获取session Gateway::getClientIdByUid($uid);
  CMD_GET_SESSION_BY_CLIENT_ID = 203;
 
 * 发给gateway，覆盖session   Gateway::setSocketSession($client_id, $session_str)
  CMD_SET_SESSION = 204;
 
#### 用户相关
 
 
* 发给gateway的向单个用户发送数据 Gateway::sendToClient($client_id, $message);
 CMD_SEND_TO_ONE = 5;

* 发给gateway的向所有用户发送数据 Gateway::sendToAll($message, $client_id_array)
 CMD_SEND_TO_ALL = 6;

* 发给gateway的踢出用户  Gateway::closeClient($client_id, $message);
 1、如果有待发消息，将在发送完后立即销毁用户连接
 2、如果无待发消息，将立即销毁用户连接
 CMD_KICK = 7;

* 发给gateway的立即销毁用户连接 Gateway::destroyClient($client_id);
 CMD_DESTROY = 8;

* 判断是否在线 Gateway::isOnline($client_id)
 CMD_IS_ONLINE = 11;
 
* client_id绑定到uid  Gateway::bindUid($client_id, $uid)
CMD_BIND_UID = 12;

* 解绑 Gateway::unbindUid($client_id, $uid);
 CMD_UNBIND_UID = 13;

* 向uid发送数据 Gateway::sendToUid($uid, $msg);
 CMD_SEND_TO_UID = 14;
 
* 根据uid获取绑定的clientid Gateway::getClientIdByUid($uid)
CMD_GET_CLIENT_ID_BY_UID = 15;

## 组相关

* 加入组 Gateway::joinGroup($client_id, $group);
 CMD_JOIN_GROUP = 20;

* 离开组 Gateway::leaveGroup($client_id, $group);
 CMD_LEAVE_GROUP = 21;

* 向组成员发消息 Gateway::sendToGroup($group, $msg);
 CMD_SEND_TO_GROUP = 22;


* 获取组在线连接数 Gateway::getClientCountByGroup($group);
 CMD_GET_CLIENT_COUNT_BY_GROUP = 24;
 
 
* 按照条件查找   Gateway::select($fields = array('session','uid','groups'), $where = array())
 CMD_SELECT = 25;

* 获取在线的群组ID  Gateway::getAllGroupIdList()
 CMD_GET_GROUP_ID_LIST = 26;

* 取消分组  Gateway::ungroup($group)
 CMD_UNGROUP = 27;
 
* worker连接gateway事件
 CMD_WORKER_CONNECT = 200;

* GatewayClient连接gateway事件
 CMD_GATEWAY_CLIENT_CONNECT = 202;


 ## flag
 
 * 包体是标量
  FLAG_BODY_IS_SCALAR = 0x01;
 
 * 通知gateway在send时不调用协议encode方法，在广播组播时提升性能
  FLAG_NOT_CALL_ENCODE = 0x02;