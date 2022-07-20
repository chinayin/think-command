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

use think\command\ThinkMNSQueueV2Command;

/**
 * MNSQueueV2Command
 */
final class MNSQueueV2Command extends ThinkMNSQueueV2Command
{
    // 命令行配置
    protected $commandName = 'example:MNSQueueV2Command';
    protected $commandDescription = 'MNSQueueV2Command';

    /** @var int 进程数 */
    protected $workerNum = 2;
    /** @var string 队列名称 */
    protected $queueName = 'test';

    protected function consume(string $message_id, array $json, $message, int $workerId = 0)
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
