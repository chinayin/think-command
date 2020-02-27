<?php

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
    protected $workerNum = 1;
    /** @var string 队列名称 */
    protected $queueName = 'test';

    protected function consume(string $message_id, array $json, $message)
    {
        try {
            __LOG_MESSAGE('consume', $message_id);
            $this->isDebugMode() && __LOG_MESSAGE($json, $message_id);
            return true;
        } catch (\Exception $e) {
            __LOG_MESSAGE($e);
        }
        return false;
    }

}
