<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
// 定义应用目录
define('TEST_PATH', __DIR__ . '/');
define('APP_PATH', __DIR__ . '/../apps/');
define('CONF_PATH', __DIR__ . '/../apps/configs/');
// 修复json_encode时精度问题
ini_set('serialize_precision', -1);
require __DIR__ . '/../vendor/chinayin/thinkphp5/base.php';
\think\App::initCommon();