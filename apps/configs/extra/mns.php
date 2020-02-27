<?php

use think\Env;

// +----------------------------------------------------------------------
// | mns
// +----------------------------------------------------------------------
return [
    'access_key_id' => Env::get('mns.access_key_id', ''),
    'access_key_secret' => Env::get('mns.access_key_secret', ''),
    'end_point' => Env::get('mns.end_point', ''),
];
