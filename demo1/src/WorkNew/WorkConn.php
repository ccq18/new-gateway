<?php

namespace GatewayWorker\WorkNew;

use GatewayWorker\WorkerHelper;
use Workerman\Connection\TcpConnection;

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Autoloader;
use Workerman\Connection\AsyncTcpConnection;
use GatewayWorker\Protocols\GatewayProtocol;

class WorkConn
{

    /**
     * Application layer protocol. todo work
     *
     * @var string
     */
    public $protocol = null;

    /**
     * 协议加速
     *
     * @var bool
     */
    public $protocolAccelerate = false;


    /**
     * 秘钥
     *
     * @var string
     */
    public $secretKey = '';

    /**
     * gateway进程转发给businessWorker进程的发送缓冲区大小
     *
     * @var int
     */
    public $sendToWorkerBufferSize = 10240000;


    /**
     * @var GateWayNew
     */
    protected $gateway;

    /**
     * WorkConn constructor.
     * @param string $protocol
     * @param bool $protocolAccelerate
     * @param array $_clientConnections
     * @param array $_uidConnections
     * @param array $_groupConnections
     * @param array $_workerConnections
     * @param string $secretKey
     * @param int $sendToWorkerBufferSize
     * @param GateWayNew $gateway
     */
    public function __construct(GateWayNew $gateway)
    {
        $this->gateway  = $gateway;
        $this->protocol = $this->gateway->protocol;
        $this->protocolAccelerate = $this->gateway->protocolAccelerate;
        $this->secretKey = $this->gateway->secretKey;
        $this->gateway = $gateway;
    }

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
     * 当worker连接关闭时
     *
     * @param TcpConnection $connection
     */
    public function onWorkerClose($connection)
    {
        if (isset($connection->key)) {
            unset($this->gateway->_workerConnections[$connection->key]);
        }
    }
    /**
     * 当 worker 发来数据时
     *
     * @param TcpConnection $connection
     * @param mixed $data
     * @throws \Exception
     *
     * @return void
     */
    public function onWorkerMessage($connection, $data)
    {
        $cmd = $data['cmd'];
        if (empty($connection->authorized) && $cmd !== GatewayProtocol::CMD_WORKER_CONNECT && $cmd !== GatewayProtocol::CMD_GATEWAY_CLIENT_CONNECT) {
            WorkerHelper::log("Unauthorized request from " . $connection->getRemoteIp() . ":" . $connection->getRemotePort());
            $connection->close();
            return;
        }
        switch ($cmd) {
            // BusinessWorker连接Gateway
            case GatewayProtocol::CMD_WORKER_CONNECT:
                $worker_info = json_decode($data['body'], true);
                if ($worker_info['secret_key'] !== $this->secretKey) {
                    WorkerHelper::log("Gateway: Worker key does not match " . var_export($this->secretKey, true) . " !== " . var_export($this->secretKey));
                    $connection->close();
                    return;
                }
                $key = $connection->getRemoteIp() . ':' . $worker_info['worker_key'];
                // 在一台服务器上businessWorker->name不能相同
                if (isset($this->gateway->_workerConnections[$key])) {
                    WorkerHelper::log("Gateway: Worker->name conflict. Key:{$key}");
                    $connection->close();
                    return;
                }
                $connection->key = $key;
                $this->gateway->_workerConnections[$key] = $connection;
                $connection->authorized = true;
                return;
            // GatewayClient连接Gateway
            case GatewayProtocol::CMD_GATEWAY_CLIENT_CONNECT:
                $worker_info = json_decode($data['body'], true);
                if ($worker_info['secret_key'] !== $this->secretKey) {
                    WorkerHelper::log("Gateway: GatewayClient key does not match " . var_export($this->secretKey, true) . " !== " . var_export($this->secretKey, true));
                    $connection->close();
                    return;
                }
                $connection->authorized = true;
                return;
            // 向某客户端发送数据，Gateway::sendToClient($client_id, $message);
            case GatewayProtocol::CMD_SEND_TO_ONE:
                if (isset($this->gateway->_clientConnections[$data['connection_id']])) {
                    $this->gateway->_clientConnections[$data['connection_id']]->send($data['body']);
                }
                return;
            // 踢出用户，Gateway::closeClient($client_id, $message);
            case GatewayProtocol::CMD_KICK:
                if (isset($this->gateway->_clientConnections[$data['connection_id']])) {
                    $this->gateway->_clientConnections[$data['connection_id']]->close($data['body']);
                }
                return;
            // 立即销毁用户连接, Gateway::destroyClient($client_id);
            case GatewayProtocol::CMD_DESTROY:
                if (isset($this->gateway->_clientConnections[$data['connection_id']])) {
                    $this->gateway->_clientConnections[$data['connection_id']]->destroy();
                }
                return;
            // 广播, Gateway::sendToAll($message, $client_id_array)
            case GatewayProtocol::CMD_SEND_TO_ALL:
                $raw = (bool)($data['flag'] & GatewayProtocol::FLAG_NOT_CALL_ENCODE);
                $body = $data['body'];
                if (!$raw && $this->protocolAccelerate && $this->protocol) {
                    $body = $this->gateway->preEncodeForClient($body);
                    $raw = true;
                }
                $ext_data = $data['ext_data'] ? json_decode($data['ext_data'], true) : '';
                // $client_id_array 不为空时，只广播给 $client_id_array 指定的客户端
                if (isset($ext_data['connections'])) {
                    foreach ($ext_data['connections'] as $connection_id) {
                        if (isset($this->gateway->_clientConnections[$connection_id])) {
                            $this->gateway->_clientConnections[$connection_id]->send($body, $raw);
                        }
                    }
                } // $client_id_array 为空时，广播给所有在线客户端
                else {
                    $exclude_connection_id = !empty($ext_data['exclude']) ? $ext_data['exclude'] : null;
                    foreach ($this->gateway->_clientConnections as $client_connection) {
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
                $where = $ext_data['where'];
                if ($where) {
                    $connection_box_map = array(
                        'groups' => $this->gateway->_groupConnections,
                        'uid' => $this->gateway->_uidConnections
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
                                if (isset($this->gateway->_clientConnections[$connection_id])) {
                                    $client_connection = $this->gateway->_clientConnections[$connection_id];
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
                    foreach ($this->gateway->_clientConnections as $connection_id => $client_connection) {
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
                $buffer = serialize(array_keys($this->gateway->_groupConnections));
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 重新赋值 session
            case GatewayProtocol::CMD_SET_SESSION:
                if (isset($this->gateway->_clientConnections[$data['connection_id']])) {
                    $this->gateway->_clientConnections[$data['connection_id']]->session = $data['ext_data'];
                }
                return;
            // session合并
            case GatewayProtocol::CMD_UPDATE_SESSION:
                if (!isset($this->gateway->_clientConnections[$data['connection_id']])) {
                    return;
                } else {
                    if (!$this->gateway->_clientConnections[$data['connection_id']]->session) {
                        $this->gateway->_clientConnections[$data['connection_id']]->session = $data['ext_data'];
                        return;
                    }
                    $session = Context::sessionDecode($this->gateway->_clientConnections[$data['connection_id']]->session);
                    $session_for_merge = Context::sessionDecode($data['ext_data']);
                    $session = array_replace_recursive($session, $session_for_merge);
                    $this->gateway->_clientConnections[$data['connection_id']]->session = Context::sessionEncode($session);
                }
                return;
            case GatewayProtocol::CMD_GET_SESSION_BY_CLIENT_ID:
                if (!isset($this->gateway->_clientConnections[$data['connection_id']])) {
                    $session = serialize(null);
                } else {
                    if (!$this->gateway->_clientConnections[$data['connection_id']]->session) {
                        $session = serialize(array());
                    } else {
                        $session = $this->gateway->_clientConnections[$data['connection_id']]->session;
                    }
                }
                $connection->send(pack('N', strlen($session)) . $session, true);
                return;
            // 获得客户端sessions
            case GatewayProtocol::CMD_GET_ALL_CLIENT_SESSIONS:
                $client_info_array = array();
                foreach ($this->gateway->_clientConnections as $connection_id => $client_connection) {
                    $client_info_array[$connection_id] = $client_connection->session;
                }
                $buffer = serialize($client_info_array);
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 判断某个 client_id 是否在线 Gateway::isOnline($client_id)
            case GatewayProtocol::CMD_IS_ONLINE:
                $buffer = serialize((int)isset($this->gateway->_clientConnections[$data['connection_id']]));
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
                if (!isset($this->gateway->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->gateway->_clientConnections[$connection_id];
                if (isset($client_connection->uid)) {
                    $current_uid = $client_connection->uid;
                    unset($this->gateway->_uidConnections[$current_uid][$connection_id]);
                    if (empty($this->gateway->_uidConnections[$current_uid])) {
                        unset($this->gateway->_uidConnections[$current_uid]);
                    }
                }
                $client_connection->uid = $uid;
                $this->gateway->_uidConnections[$uid][$connection_id] = $client_connection;
                return;
            // client_id 与 uid 解绑 Gateway::unbindUid($client_id, $uid);
            case GatewayProtocol::CMD_UNBIND_UID:
                $connection_id = $data['connection_id'];
                if (!isset($this->gateway->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->gateway->_clientConnections[$connection_id];
                if (isset($client_connection->uid)) {
                    $current_uid = $client_connection->uid;
                    unset($this->gateway->_uidConnections[$current_uid][$connection_id]);
                    if (empty($this->gateway->_uidConnections[$current_uid])) {
                        unset($this->gateway->_uidConnections[$current_uid]);
                    }
                    $client_connection->uid_info = '';
                    $client_connection->uid = null;
                }
                return;
            // 发送数据给 uid Gateway::sendToUid($uid, $msg);
            case GatewayProtocol::CMD_SEND_TO_UID:
                $uid_array = json_decode($data['ext_data'], true);
                foreach ($uid_array as $uid) {
                    if (!empty($this->gateway->_uidConnections[$uid])) {
                        foreach ($this->gateway->_uidConnections[$uid] as $connection) {
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
                if (!isset($this->gateway->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->gateway->_clientConnections[$connection_id];
                if (!isset($client_connection->groups)) {
                    $client_connection->groups = array();
                }
                $client_connection->groups[$group] = $group;
                $this->gateway->_groupConnections[$group][$connection_id] = $client_connection;
                return;
            // 将 $client_id 从某个用户组中移除 Gateway::leaveGroup($client_id, $group);
            case GatewayProtocol::CMD_LEAVE_GROUP:
                $group = $data['ext_data'];
                if (empty($group)) {
                    echo "leave(group) group empty, group=" . var_export($group, true);
                    return;
                }
                $connection_id = $data['connection_id'];
                if (!isset($this->gateway->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->gateway->_clientConnections[$connection_id];
                if (!isset($client_connection->groups[$group])) {
                    return;
                }
                unset($client_connection->groups[$group], $this->gateway->_groupConnections[$group][$connection_id]);
                if (empty($this->gateway->_groupConnections[$group])) {
                    unset($this->gateway->_groupConnections[$group]);
                }
                return;
            // 解散分组
            case GatewayProtocol::CMD_UNGROUP:
                $group = $data['ext_data'];
                if (empty($group)) {
                    echo "leave(group) group empty, group=" . var_export($group, true);
                    return;
                }
                if (empty($this->gateway->_groupConnections[$group])) {
                    return;
                }
                foreach ($this->gateway->_groupConnections[$group] as $client_connection) {
                    unset($client_connection->groups[$group]);
                }
                unset($this->gateway->_groupConnections[$group]);
                return;
            // 向某个用户组发送消息 Gateway::sendToGroup($group, $msg);
            case GatewayProtocol::CMD_SEND_TO_GROUP:
                $raw = (bool)($data['flag'] & GatewayProtocol::FLAG_NOT_CALL_ENCODE);
                $body = $data['body'];
                if (!$raw && $this->protocolAccelerate && $this->protocol) {
                    $body = $this->gateway->preEncodeForClient($body);
                    $raw = true;
                }
                $ext_data = json_decode($data['ext_data'], true);
                $group_array = $ext_data['group'];
                $exclude_connection_id = $ext_data['exclude'];

                foreach ($group_array as $group) {
                    if (!empty($this->gateway->_groupConnections[$group])) {
                        foreach ($this->gateway->_groupConnections[$group] as $connection) {
                            if (!isset($exclude_connection_id[$connection->id])) {
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
                if (!isset($this->gateway->_groupConnections[$group])) {
                    $buffer = serialize(array());
                    $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                    return;
                }
                $client_info_array = array();
                foreach ($this->gateway->_groupConnections[$group] as $connection_id => $client_connection) {
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
                    if (isset($this->gateway->_groupConnections[$group])) {
                        $count = count($this->gateway->_groupConnections[$group]);
                    }
                } else {
                    $count = count($this->gateway->_clientConnections);
                }
                $buffer = serialize($count);
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 获取与某个 uid 绑定的所有 client_id Gateway::getClientIdByUid($uid);
            case GatewayProtocol::CMD_GET_CLIENT_ID_BY_UID:
                $uid = $data['ext_data'];
                if (empty($this->gateway->_uidConnections[$uid])) {
                    $buffer = serialize(array());
                } else {
                    $buffer = serialize(array_keys($this->gateway->_uidConnections[$uid]));
                }
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            default :
                $err_msg = "gateway inner pack err cmd=$cmd";
                echo $err_msg;
        }
    }
}