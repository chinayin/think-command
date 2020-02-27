<?php

use think\Env;

// +----------------------------------------------------------------------
// | mq
// +----------------------------------------------------------------------
return [
    'access_key_id' => Env::get('mq.access_key_id', ''),
    'access_key_secret' => Env::get('mq.access_key_secret', ''),
    'end_point' => Env::get('mq.end_point', ''),
    'instance_id' => Env::get('mq.instance_id', ''),
];
