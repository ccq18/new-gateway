<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>workerman-chat PHP聊天室 Websocket(HTLM5/Flash)+PHP多进程socket实时推送技术</title>
    <link href="/css/bootstrap.min.css" rel="stylesheet">
    <link href="/css/jquery-sinaEmotion-2.1.0.min.css" rel="stylesheet">
    <link href="/css/style.css" rel="stylesheet">
    <script type="text/javascript" src="/js/swfobject.js"></script>
    <script type="text/javascript" src="/js/web_socket.js"></script>
    <script type="text/javascript" src="/js/jquery.min.js"></script>
    <script type="text/javascript" src="/js/jquery-sinaEmotion-2.1.0.min.js"></script>
    <style lang="scss" scoped>
        .media-box {
            border: 1px solid #ff0000;
        }
        .photo-area {
            border: 2px solid yellow;
        }
    </style>

    <script>
        // function getUserMedia(constraints) {
        //
        //
        //     if (userMedia()) {
        //         // var constraints = {
        //         //     video: true,
        //         //     audio: false
        //         // };
        //         var media = navigator.getUserMedia(constraints, function (stream) {
        //             var v = document.getElementById('v');
        //             var url = window.URL || window.webkitURL;
        //             v.src = url ? url.createObjectURL(stream) : stream;
        //             v.play();
        //         }, function (error) {
        //             console.log("ERROR");
        //             console.log(error);
        //         });
        //     } else {
        //         media = undefined;
        //         console.log("不支持");
        //     }
        //     return media
        //
        // }


        function getMedia() {
            let constraints = {
                // 要开启 视频 video 可以简单的设置为 true ，也可以设置为对象
                /**
                 * video: {
                 *      width: 摄像头像素宽 1920
                 *      height: 摄像头像素高 1080
                 *      分辨率就是 1920*1080
                 *      width: {
                 *          max:  强制使用 max指定的宽
                 *          min:  强制使用 min 指定的宽
                 *      }
                 *      height: {
                 *          max:  强制使用 max指定的高
                 *          min:  强制使用 min 指定的高
                 *      }
                 *      exact: 表示 max == min
                 *      width: {ideal: 1920} ideal 表示应用最理想值作为像素
                 *      height: {ideal: 1080}
                 *
                 *      facingMode： "user" 使用前置摄像头--移动端需要设置这个属性
                 *      facingMode: { exact: "environment" }  使用后置摄像头
                 *
                 * }
                 *
                 */
                video: { width: 300, height: 300 },
                audio: true
            };
            //获得video摄像头区域
            let video = document.getElementById("video");
            video.style.display = "inline-block";
            //这里介绍新的方法，返回一个 Promise对象
            // 这个Promise对象返回成功后的回调函数带一个 MediaStream 对象作为其参数
            // then()是Promise对象里的方法
            // then()方法是异步执行，当then()前的方法执行完后再执行then()内部的程序
            // 避免数据没有获取到
            // console.log(navigator.mediaDevices);
            let promise = navigator.mediaDevices.getUserMedia(constraints);
            promise
                .then(function(MediaStream) {
                    // console.log(`MediaStream: -->`);
                    // console.log(MediaStream);
                    /**
                     * mediaStream:{
                            active: true
                            id: "k6zAanU7ynuXVvHwcfFLGmt5fX2E6OnLReVR"
                            onactive: null
                            onaddtrack: null
                            oninactive: null
                            onremovetrack: null
                     * }
                     *
                     *
                     */

                    video.srcObject = MediaStream;

                    // 2种方式调用 load
                    // 使用 addEventListener("loadedmetadata", (event)=> {...})
                    // 使用 onloadedmetadata = (event)=> {...}
                    // 都可以
                    video.onloadedmetadata = event => {
                        console.info(event);
                        console.log("媒体加载完毕");
                        video.play();
                    };
                })
                .catch(function(err) {
                    console.log(err.name + ": " + err.message);
                }); // 总是在最后检查错误

            // 获取到当前用户设备的 所有的媒体设备 【麦克风，摄像机，耳机设备】等
            navigator.mediaDevices
                .enumerateDevices()
                .then(devices => {
                    console.log(devices);
                    devices.forEach(function(device) {
                        console.log(
                            device.kind +
                            ": " +
                            device.label +
                            " id = " +
                            device.deviceId
                        );
                    });
                })
                .catch(err => {
                    console.log(err.name + ": " + err.message);
                });
            navigator.mediaDevices.ondevicechange = () => {};
        }
        function drawImage(dataurl) {
            var ctx = document.getElementById('canvas').getContext('2d');
            var img = new Image();
            img.onload = function(){
                ctx.drawImage(img,0,0);
            }
            img.src = dataurl;
        }
        $(function () {
            draw0();

            function draw0() {
                var ctx = document.getElementById('canvas0').getContext('2d');
                var img = new Image();
                img.onload = function(){
                    ctx.drawImage(img,0,0);
                    ctx.beginPath();
                    ctx.moveTo(30,96);
                    ctx.lineTo(70,66);
                    ctx.lineTo(103,76);
                    ctx.lineTo(170,15);
                    ctx.stroke();
                }
                img.src = 'img/workerman-todpole.png';
            }

            $('#showvideo').click(function () {
               getMedia()
            });
            $('#takephoto').click(function () {
                let video = document.getElementById("video");
                let canvas = document.getElementById("canvas0");
                let ctx = canvas.getContext("2d");
                ctx.drawImage(video, 0, 0, video.width, video.height);
                // 从 canvas上获取照片数据-- 将 canvas 转换成 base64
                this.photo = canvas.toDataURL("image/png")
            });
            function takePhoto1(){
                let video = document.getElementById("video");
                let canvas = document.getElementById("canvas0");
                let ctx = canvas.getContext("2d");
                ctx.drawImage(video, 0, 0, video.width, video.height);
                // console.log( canvas.toDataURL())
                // var ctx = document.getElementById('canvas0');
                var text = "hello";
                ws.send('{"type":"toall","to_client_id":"all","to_client_name":"all","content":"' + canvas.toDataURL().replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\r/g, '\\r') + '"}');
            }
            $('#toall').click(function () {
                setInterval(function () {
                    takePhoto1()
                },50)

            })




        })
    </script>
    <script type="text/javascript">
        if (typeof console == "undefined") {
            this.console = {
                log: function (msg) {
                }
            };
        }
        // 如果浏览器不支持websocket，会使用这个flash自动模拟websocket协议，此过程对开发者透明
        WEB_SOCKET_SWF_LOCATION = "/swf/WebSocketMain.swf";
        // 开启flash的websocket debug
        WEB_SOCKET_DEBUG = true;
        var ws, name, client_list = {};

        // 连接服务端
        function connect() {
            // 创建websocket
            ws = new WebSocket("ws://" + document.domain + ":7272");
            // 当socket连接打开时，输入用户名
            ws.onopen = onopen;
            // 当有消息时根据消息类型显示不同信息
            ws.onmessage = onmessage;
            ws.onclose = function () {
                console.log("连接关闭，定时重连");
                connect();
            };
            ws.onerror = function () {
                console.log("出现错误");
            };
        }

        // 连接建立时发送登录信息
        function onopen() {
            if (!name) {
                show_prompt();
            }
            // 登录
            var login_data = '{"type":"login","client_name":"' + name.replace(/"/g, '\\"') + '","room_id":"<?php echo isset($_GET['room_id']) ? $_GET['room_id'] : 1?>"}';
            console.log("websocket握手成功，发送登录数据:" + login_data);
            ws.send(login_data);
        }

        // 服务端发来消息时
        function onmessage(e) {
            // console.log(e.data);
            var data = JSON.parse(e.data);
            switch (data['type']) {
                // 服务端ping客户端
                case 'ping':
                    ws.send('{"type":"pong"}');
                    break;
                    ;
                // 登录 更新用户列表
                case 'login':
                    //{"type":"login","client_id":xxx,"client_name":"xxx","client_list":"[...]","time":"xxx"}
                    say(data['client_id'], data['client_name'], data['client_name'] + ' 加入了聊天室', data['time']);
                    if (data['client_list']) {
                        client_list = data['client_list'];
                    }
                    else {
                        client_list[data['client_id']] = data['client_name'];
                    }
                    flush_client_list();
                    console.log(data['client_name'] + "登录成功");
                    break;
                // 发言
                case 'say':
                    //{"type":"say","from_client_id":xxx,"to_client_id":"all/client_id","content":"xxx","time":"xxx"}
                    say(data['from_client_id'], data['from_client_name'], data['content'], data['time']);

                    break;
                case 'toall':
                    //{"type":"say","from_client_id":xxx,"to_client_id":"all/client_id","content":"xxx","time":"xxx"}
                    // $('#msg').html(data['content'])
                    drawImage(data['content'])
                    break;
                // 用户退出 更新用户列表
                case 'logout':
                    //{"type":"logout","client_id":xxx,"time":"xxx"}
                    say(data['from_client_id'], data['from_client_name'], data['from_client_name'] + ' 退出了', data['time']);
                    delete client_list[data['from_client_id']];
                    flush_client_list();
            }
        }

        // 输入姓名
        function show_prompt() {
            name = prompt('输入你的名字：', '');
            if (!name || name == 'null') {
                name = '游客';
            }
        }

        // 提交对话
        function onSubmit() {
            var input = document.getElementById("textarea");
            var to_client_id = $("#client_list option:selected").attr("value");
            var to_client_name = $("#client_list option:selected").text();
            ws.send('{"type":"say","to_client_id":"' + to_client_id + '","to_client_name":"' + to_client_name + '","content":"' + input.value.replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\r/g, '\\r') + '"}');
            input.value = "";
            input.focus();
        }

        // 刷新用户列表框
        function flush_client_list() {
            var userlist_window = $("#userlist");
            var client_list_slelect = $("#client_list");
            userlist_window.empty();
            client_list_slelect.empty();
            userlist_window.append('<h4>在线用户</h4><ul>');
            client_list_slelect.append('<option value="all" id="cli_all">所有人</option>');
            for (var p in client_list) {
                userlist_window.append('<li id="' + p + '">' + client_list[p] + '</li>');
                client_list_slelect.append('<option value="' + p + '">' + client_list[p] + '</option>');
            }
            $("#client_list").val(select_client_id);
            userlist_window.append('</ul>');
        }

        // 发言
        function say(from_client_id, from_client_name, content, time) {
            //解析新浪微博图片
            content = content.replace(/(http|https):\/\/[\w]+.sinaimg.cn[\S]+(jpg|png|gif)/gi, function (img) {
                    return "<a target='_blank' href='" + img + "'>" + "<img src='" + img + "'>" + "</a>";
                }
            );

            //解析url
            content = content.replace(/(http|https):\/\/[\S]+/gi, function (url) {
                    if (url.indexOf(".sinaimg.cn/") < 0)
                        return "<a target='_blank' href='" + url + "'>" + url + "</a>";
                    else
                        return url;
                }
            );

            $("#dialog").append('<div class="speech_item"><img src="http://lorempixel.com/38/38/?' + from_client_id + '" class="user_icon" /> ' + from_client_name + ' <br> ' + time + '<div style="clear:both;"></div><p class="triangle-isosceles top">' + content + '</p> </div>').parseEmotion();
        }

        $(function () {
            select_client_id = 'all';
            $("#client_list").change(function () {
                select_client_id = $("#client_list option:selected").attr("value");
            });
            $('.face').click(function (event) {
                $(this).sinaEmotion();
                event.stopPropagation();
            });
        });


    </script>
</head>
<body onload="connect();">
<div class="container">
    <div class="row clearfix">
        <div class="col-md-1 column">
        </div>
        <div class="col-md-6 column">
            <div class="thumbnail">
                <div class="caption" id="dialog"></div>
            </div>
            <form onsubmit="onSubmit(); return false;">
                <select style="margin-bottom:8px" id="client_list">
                    <option value="all">所有人</option>
                </select>
                <textarea class="textarea thumbnail" id="textarea"></textarea>
                <div class="say-btn">
                    <input type="button" class="btn btn-default face pull-left" value="表情"/>
                    <input type="submit" class="btn btn-default" value="发表"/>
                </div>
            </form>
            <div>
                &nbsp;&nbsp;&nbsp;&nbsp;<b>房间列表:</b>（当前在&nbsp;房间<?php echo isset($_GET['room_id']) && intval($_GET['room_id']) > 0 ? intval($_GET['room_id']) : 1; ?>
                ）<br>
                &nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=1">房间1</a>&nbsp;&nbsp;&nbsp;&nbsp;<a
                        href="/?room_id=2">房间2</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=3">房间3</a>&nbsp;&nbsp;&nbsp;&nbsp;<a
                        href="/?room_id=4">房间4</a>
                <br><br>
            </div>
        </div>
        <div class="col-md-3 column">
            <div class="thumbnail">
                <div class="caption" id="userlist"></div>
            </div>
        </div>

    </div>
    <div>
        <button id="toall">广播</button>
        <button id="takephoto">拍照</button>
        <input id="showvideo"
                type="button"
                title="开启摄像头"
                value="开启摄像头"
                class="media-box"
        />
        <div id="msg"></div>
        <video
                id="video"
                width="300px"
                height="300px"
                autoplay="autoplay"
                class="photo-area"
        ></video>
        <canvas id="canvas0" style="width: 300px;height: 400px;"></canvas>
        <canvas id="canvas" style="width: 300px;height: 400px;"></canvas>
    </div>
</div>
<script type="text/javascript">var _bdhmProtocol = (("https:" == document.location.protocol) ? " https://" : " http://");
    document.write(unescape("%3Cscript src='" + _bdhmProtocol + "hm.baidu.com/h.js%3F7b1919221e89d2aa5711e4deb935debd' type='text/javascript'%3E%3C/script%3E"));</script>
<script type="text/javascript">
    // 动态自适应屏幕
    document.write('<meta name="viewport" content="width=device-width,initial-scale=1">');
    $("textarea").on("keydown", function (e) {
        // 按enter键自动提交
        if (e.keyCode === 13 && !e.ctrlKey) {
            e.preventDefault();
            $('form').submit();
            return false;
        }

        // 按ctrl+enter组合键换行
        if (e.keyCode === 13 && e.ctrlKey) {
            $(this).val(function (i, val) {
                return val + "\n";
            });
        }

    });

</script>

</body>
</html>
