<?php

namespace GatewayWorker\Component;

use GatewayWorker\Lib\Context;

use Workerman\Connection\TcpConnection;
use GatewayWorker\Protocols\GatewayProtocol;

trait WorkerEvent
{

    /**
     * 保存所有 worker 的内部连接的 connection 对象
     *
     * @var array
     */
    protected $_workerConnections = array();

    /**
     * 当 worker 通过内部通讯端口连接到 gateway 时
     *
     * @param TcpConnection $connection
     */
    public function onWorkerConnect($connection)
    {
        $connection->maxSendBufferSize = $this->sendToWorkerBufferSize;
        $connection->authorized = $this->secretKey ? false : true;
    }

    /**
     * 当 worker 发来数据时
     *
     * @param TcpConnection $connection
     * @param mixed         $data
     * @throws \Exception
     *
     * @return void
     */
    public function onWorkerMessage($connection, $data)
    {
        $cmd = $data['cmd'];
        if (empty($connection->authorized) && $cmd !== GatewayProtocol::CMD_WORKER_CONNECT && $cmd !== GatewayProtocol::CMD_GATEWAY_CLIENT_CONNECT) {
            self::log("Unauthorized request from " . $connection->getRemoteIp() . ":" . $connection->getRemotePort());
            $connection->close();
            return;
        }
        switch ($cmd) {
            // BusinessWorker连接Gateway
            case GatewayProtocol::CMD_WORKER_CONNECT:
                $worker_info = json_decode($data['body'], true);
                if ($worker_info['secret_key'] !== $this->secretKey) {
                    self::log("Gateway: Worker key does not match ".var_export($this->secretKey, true)." !== ". var_export($this->secretKey));
                    $connection->close();
                    return;
                }
                $key = $connection->getRemoteIp() . ':' . $worker_info['worker_key'];
                // 在一台服务器上businessWorker->name不能相同
                if (isset($this->_workerConnections[$key])) {
                    self::log("Gateway: Worker->name conflict. Key:{$key}");
                    $connection->close();
                    return;
                }
                $connection->key = $key;
                $this->_workerConnections[$key] = $connection;
                $connection->authorized = true;
                return;
            // GatewayClient连接Gateway
            case GatewayProtocol::CMD_GATEWAY_CLIENT_CONNECT:
                $worker_info = json_decode($data['body'], true);
                if ($worker_info['secret_key'] !== $this->secretKey) {
                    self::log("Gateway: GatewayClient key does not match ".var_export($this->secretKey, true)." !== ".var_export($this->secretKey, true));
                    $connection->close();
                    return;
                }
                $connection->authorized = true;
                return;
            // 向某客户端发送数据，Gateway::sendToClient($client_id, $message);
            case GatewayProtocol::CMD_SEND_TO_ONE:
                if (isset($this->_clientConnections[$data['connection_id']])) {
                    $this->_clientConnections[$data['connection_id']]->send($data['body']);
                }
                return;
            // 踢出用户，Gateway::closeClient($client_id, $message);
            case GatewayProtocol::CMD_KICK:
                if (isset($this->_clientConnections[$data['connection_id']])) {
                    $this->_clientConnections[$data['connection_id']]->close($data['body']);
                }
                return;
            // 立即销毁用户连接, Gateway::destroyClient($client_id);
            case GatewayProtocol::CMD_DESTROY:
                if (isset($this->_clientConnections[$data['connection_id']])) {
                    $this->_clientConnections[$data['connection_id']]->destroy();
                }
                return;
            // 广播, Gateway::sendToAll($message, $client_id_array)
            case GatewayProtocol::CMD_SEND_TO_ALL:
                $raw = (bool)($data['flag'] & GatewayProtocol::FLAG_NOT_CALL_ENCODE);
                $body = $data['body'];
                if (!$raw && $this->protocolAccelerate && $this->protocol) {
                    $body = $this->preEncodeForClient($body);
                    $raw = true;
                }
                $ext_data = $data['ext_data'] ? json_decode($data['ext_data'], true) : '';
                // $client_id_array 不为空时，只广播给 $client_id_array 指定的客户端
                if (isset($ext_data['connections'])) {
                    foreach ($ext_data['connections'] as $connection_id) {
                        if (isset($this->_clientConnections[$connection_id])) {
                            $this->_clientConnections[$connection_id]->send($body, $raw);
                        }
                    }
                } // $client_id_array 为空时，广播给所有在线客户端
                else {
                    $exclude_connection_id = !empty($ext_data['exclude']) ? $ext_data['exclude'] : null;
                    foreach ($this->_clientConnections as $client_connection) {
                        if (!isset($exclude_connection_id[$client_connection->id])) {
                            $client_connection->send($body, $raw);
                        }
                    }
                }
                return;
            case GatewayProtocol::CMD_SELECT:
                $client_info_array = array();
                $ext_data = json_decode($data['ext_data'], true);
                if (!$ext_data) {
                    echo 'CMD_SELECT ext_data=' . var_export($data['ext_data'], true) . '\r\n';
                    $buffer = serialize($client_info_array);
                    $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                    return;
                }
                $fields = $ext_data['fields'];
                $where  = $ext_data['where'];
                if ($where) {
                    $connection_box_map = array(
                        'groups'        => $this->_groupConnections,
                        'uid'           => $this->_uidConnections
                    );
                    // $where = ['groups'=>[x,x..], 'uid'=>[x,x..], 'connection_id'=>[x,x..]]
                    foreach ($where as $key => $items) {
                        if ($key !== 'connection_id') {
                            $connections_box = $connection_box_map[$key];
                            foreach ($items as $item) {
                                if (isset($connections_box[$item])) {
                                    foreach ($connections_box[$item] as $connection_id => $client_connection) {
                                        if (!isset($client_info_array[$connection_id])) {
                                            $client_info_array[$connection_id] = array();
                                            // $fields = ['groups', 'uid', 'session']
                                            foreach ($fields as $field) {
                                                $client_info_array[$connection_id][$field] = isset($client_connection->$field) ? $client_connection->$field : null;
                                            }
                                        }
                                    }

                                }
                            }
                        } else {
                            foreach ($items as $connection_id) {
                                if (isset($this->_clientConnections[$connection_id])) {
                                    $client_connection = $this->_clientConnections[$connection_id];
                                    $client_info_array[$connection_id] = array();
                                    // $fields = ['groups', 'uid', 'session']
                                    foreach ($fields as $field) {
                                        $client_info_array[$connection_id][$field] = isset($client_connection->$field) ? $client_connection->$field : null;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    foreach ($this->_clientConnections as $connection_id => $client_connection) {
                        foreach ($fields as $field) {
                            $client_info_array[$connection_id][$field] = isset($client_connection->$field) ? $client_connection->$field : null;
                        }
                    }
                }
                $buffer = serialize($client_info_array);
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 获取在线群组列表
            case GatewayProtocol::CMD_GET_GROUP_ID_LIST:
                $buffer = serialize(array_keys($this->_groupConnections));
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 重新赋值 session
            case GatewayProtocol::CMD_SET_SESSION:
                if (isset($this->_clientConnections[$data['connection_id']])) {
                    $this->_clientConnections[$data['connection_id']]->session = $data['ext_data'];
                }
                return;
            // session合并
            case GatewayProtocol::CMD_UPDATE_SESSION:
                if (!isset($this->_clientConnections[$data['connection_id']])) {
                    return;
                } else {
                    if (!$this->_clientConnections[$data['connection_id']]->session) {
                        $this->_clientConnections[$data['connection_id']]->session = $data['ext_data'];
                        return;
                    }
                    $session = Context::sessionDecode($this->_clientConnections[$data['connection_id']]->session);
                    $session_for_merge = Context::sessionDecode($data['ext_data']);
                    $session = array_replace_recursive($session, $session_for_merge);
                    $this->_clientConnections[$data['connection_id']]->session = Context::sessionEncode($session);
                }
                return;
            case GatewayProtocol::CMD_GET_SESSION_BY_CLIENT_ID:
                if (!isset($this->_clientConnections[$data['connection_id']])) {
                    $session = serialize(null);
                } else {
                    if (!$this->_clientConnections[$data['connection_id']]->session) {
                        $session = serialize(array());
                    } else {
                        $session = $this->_clientConnections[$data['connection_id']]->session;
                    }
                }
                $connection->send(pack('N', strlen($session)) . $session, true);
                return;
            // 获得客户端sessions
            case GatewayProtocol::CMD_GET_ALL_CLIENT_SESSIONS:
                $client_info_array = array();
                foreach ($this->_clientConnections as $connection_id => $client_connection) {
                    $client_info_array[$connection_id] = $client_connection->session;
                }
                $buffer = serialize($client_info_array);
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 判断某个 client_id 是否在线 Gateway::isOnline($client_id)
            case GatewayProtocol::CMD_IS_ONLINE:
                $buffer = serialize((int)isset($this->_clientConnections[$data['connection_id']]));
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 将 client_id 与 uid 绑定
            case GatewayProtocol::CMD_BIND_UID:
                $uid = $data['ext_data'];
                if (empty($uid)) {
                    echo "bindUid(client_id, uid) uid empty, uid=" . var_export($uid, true);
                    return;
                }
                $connection_id = $data['connection_id'];
                if (!isset($this->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->_clientConnections[$connection_id];
                if (isset($client_connection->uid)) {
                    $current_uid = $client_connection->uid;
                    unset($this->_uidConnections[$current_uid][$connection_id]);
                    if (empty($this->_uidConnections[$current_uid])) {
                        unset($this->_uidConnections[$current_uid]);
                    }
                }
                $client_connection->uid                      = $uid;
                $this->_uidConnections[$uid][$connection_id] = $client_connection;
                return;
            // client_id 与 uid 解绑 Gateway::unbindUid($client_id, $uid);
            case GatewayProtocol::CMD_UNBIND_UID:
                $connection_id = $data['connection_id'];
                if (!isset($this->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->_clientConnections[$connection_id];
                if (isset($client_connection->uid)) {
                    $current_uid = $client_connection->uid;
                    unset($this->_uidConnections[$current_uid][$connection_id]);
                    if (empty($this->_uidConnections[$current_uid])) {
                        unset($this->_uidConnections[$current_uid]);
                    }
                    $client_connection->uid_info = '';
                    $client_connection->uid      = null;
                }
                return;
            // 发送数据给 uid Gateway::sendToUid($uid, $msg);
            case GatewayProtocol::CMD_SEND_TO_UID:
                $uid_array = json_decode($data['ext_data'], true);
                foreach ($uid_array as $uid) {
                    if (!empty($this->_uidConnections[$uid])) {
                        foreach ($this->_uidConnections[$uid] as $connection) {
                            /** @var TcpConnection $connection */
                            $connection->send($data['body']);
                        }
                    }
                }
                return;
            // 将 $client_id 加入用户组 Gateway::joinGroup($client_id, $group);
            case GatewayProtocol::CMD_JOIN_GROUP:
                $group = $data['ext_data'];
                if (empty($group)) {
                    echo "join(group) group empty, group=" . var_export($group, true);
                    return;
                }
                $connection_id = $data['connection_id'];
                if (!isset($this->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->_clientConnections[$connection_id];
                if (!isset($client_connection->groups)) {
                    $client_connection->groups = array();
                }
                $client_connection->groups[$group]               = $group;
                $this->_groupConnections[$group][$connection_id] = $client_connection;
                return;
            // 将 $client_id 从某个用户组中移除 Gateway::leaveGroup($client_id, $group);
            case GatewayProtocol::CMD_LEAVE_GROUP:
                $group = $data['ext_data'];
                if (empty($group)) {
                    echo "leave(group) group empty, group=" . var_export($group, true);
                    return;
                }
                $connection_id = $data['connection_id'];
                if (!isset($this->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->_clientConnections[$connection_id];
                if (!isset($client_connection->groups[$group])) {
                    return;
                }
                unset($client_connection->groups[$group], $this->_groupConnections[$group][$connection_id]);
                if (empty($this->_groupConnections[$group])) {
                    unset($this->_groupConnections[$group]);
                }
                return;
            // 解散分组
            case GatewayProtocol::CMD_UNGROUP:
                $group = $data['ext_data'];
                if (empty($group)) {
                    echo "leave(group) group empty, group=" . var_export($group, true);
                    return;
                }
                if (empty($this->_groupConnections[$group])) {
                    return;
                }
                foreach ($this->_groupConnections[$group] as $client_connection) {
                    unset($client_connection->groups[$group]);
                }
                unset($this->_groupConnections[$group]);
                return;
            // 向某个用户组发送消息 Gateway::sendToGroup($group, $msg);
            case GatewayProtocol::CMD_SEND_TO_GROUP:
                $raw = (bool)($data['flag'] & GatewayProtocol::FLAG_NOT_CALL_ENCODE);
                $body = $data['body'];
                if (!$raw && $this->protocolAccelerate && $this->protocol) {
                    $body = $this->preEncodeForClient($body);
                    $raw = true;
                }
                $ext_data = json_decode($data['ext_data'], true);
                $group_array = $ext_data['group'];
                $exclude_connection_id = $ext_data['exclude'];

                foreach ($group_array as $group) {
                    if (!empty($this->_groupConnections[$group])) {
                        foreach ($this->_groupConnections[$group] as $connection) {
                            if(!isset($exclude_connection_id[$connection->id]))
                            {
                                /** @var TcpConnection $connection */
                                $connection->send($body, $raw);
                            }
                        }
                    }
                }
                return;
            // 获取某用户组成员信息 Gateway::getClientSessionsByGroup($group);
            case GatewayProtocol::CMD_GET_CLIENT_SESSIONS_BY_GROUP:
                $group = $data['ext_data'];
                if (!isset($this->_groupConnections[$group])) {
                    $buffer = serialize(array());
                    $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                    return;
                }
                $client_info_array = array();
                foreach ($this->_groupConnections[$group] as $connection_id => $client_connection) {
                    $client_info_array[$connection_id] = $client_connection->session;
                }
                $buffer = serialize($client_info_array);
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 获取用户组成员数 Gateway::getClientCountByGroup($group);
            case GatewayProtocol::CMD_GET_CLIENT_COUNT_BY_GROUP:
                $group = $data['ext_data'];
                $count = 0;
                if ($group !== '') {
                    if (isset($this->_groupConnections[$group])) {
                        $count = count($this->_groupConnections[$group]);
                    }
                } else {
                    $count = count($this->_clientConnections);
                }
                $buffer = serialize($count);
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 获取与某个 uid 绑定的所有 client_id Gateway::getClientIdByUid($uid);
            case GatewayProtocol::CMD_GET_CLIENT_ID_BY_UID:
                $uid = $data['ext_data'];
                if (empty($this->_uidConnections[$uid])) {
                    $buffer = serialize(array());
                } else {
                    $buffer = serialize(array_keys($this->_uidConnections[$uid]));
                }
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            default :
                $err_msg = "gateway inner pack err cmd=$cmd";
                echo $err_msg;
        }
    }


    /**
     * 当worker连接关闭时
     *
     * @param TcpConnection $connection
     */
    public function onWorkerClose($connection)
    {
        if (isset($connection->key)) {
            unset($this->_workerConnections[$connection->key]);
        }
    }

    /**
     * 向 BusinessWorker 发送心跳数据，用于保持长连接
     *
     * @return void
     */
    public function pingBusinessWorker()
    {
        $gateway_data        = GatewayProtocol::$empty;
        $gateway_data['cmd'] = GatewayProtocol::CMD_PING;
        foreach ($this->_workerConnections as $connection) {
            $connection->send($gateway_data);
        }
    }

    /**
     * 发送数据给 worker 进程
     *
     * @param int           $cmd
     * @param TcpConnection $connection
     * @param mixed         $body
     * @return bool
     */
    protected function sendToWorker($cmd, $connection, $body = '')
    {
        $gateway_data             = $connection->gatewayHeader;
        $gateway_data['cmd']      = $cmd;
        $gateway_data['body']     = $body;
        $gateway_data['ext_data'] = $connection->session;
        if ($this->_workerConnections) {
            // 调用路由函数，选择一个worker把请求转发给它
            /** @var TcpConnection $worker_connection */
            $worker_connection = call_user_func($this->router, $this->_workerConnections, $connection, $cmd, $body);
            if (false === $worker_connection->send($gateway_data)) {
                $msg = "SendBufferToWorker fail. May be the send buffer are overflow. See http://doc2.workerman.net/send-buffer-overflow.html";
                static::log($msg);
                return false;
            }
        } // 没有可用的 worker
        else {
            // gateway 启动后 1-2 秒内 SendBufferToWorker fail 是正常现象，因为与 worker 的连接还没建立起来，
            // 所以不记录日志，只是关闭连接
            $time_diff = 2;
            if (time() - $this->_startTime >= $time_diff) {
                $msg = 'SendBufferToWorker fail. The connections between Gateway and BusinessWorker are not ready. See http://doc2.workerman.net/send-buffer-to-worker-fail.html';
                static::log($msg);
            }
            $connection->destroy();
            return false;
        }
        return true;
    }

}