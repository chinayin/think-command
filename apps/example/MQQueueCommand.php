<?php

/*
 * This file is part of the think-command package.
 *
 * @link   https://github.com/chinayin/think-command
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace app\example;

use think\command\ThinkMQQueueCommand;

/**
 * MQQueueCommand
 */
final class MQQueueCommand extends ThinkMQQueueCommand
{
    // 命令行配置
    protected $commandName = 'example:MQQueueCommand';
    protected $commandDescription = 'MQQueueCommand';
    /** @var int 进程数 */
    protected $workerNum = 1;
    // 组ID
    protected $groupId = 'GID-hunt';
    // 主题名称
    protected $topicName = 'test';
    // 主题tag
    protected $messageTag = 'test2';

    protected function consume(string $message_id, array $json, array $properties, $message, int $workerId = 0)
    {
        try {
            __LOG_MESSAGE($message_id, 'consume');
            $this->isDebugMode() && __LOG_MESSAGE($json, $message_id);
            return true;
        } catch (\Exception $e) {
            __LOG_MESSAGE($e);
        }
        return false;
    }
}
