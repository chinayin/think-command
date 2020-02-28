<?php

use think\Env;

// +----------------------------------------------------------------------
// | aliyun ram
// +----------------------------------------------------------------------
return [
    [
        // MNS - 消息服务
        'mns' => [
            'access_key_id' => Env::get('mns.access_key_id', ''),
            'access_key_secret' => Env::get('mns.access_key_secret', ''),
            'end_point' => Env::get('mns.end_point', ''),
        ],
        // MQ - RocketMQ
        'mq' => [
            'access_key_id' => Env::get('mq.access_key_id', ''),
            'access_key_secret' => Env::get('mq.access_key_secret', ''),
            'end_point' => Env::get('mq.end_point', ''),
            'instance_id' => Env::get('mq.instance_id', ''),
        ],
    ]
];
