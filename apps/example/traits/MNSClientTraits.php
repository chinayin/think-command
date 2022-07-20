<?php

/*
 * This file is part of the think-command package.
 *
 * @link   https://github.com/chinayin/think-command
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace app\example\traits;

use AliyunMNS\Client as MnsClient;
use AliyunMNS\Requests\SendMessageRequest;

trait MNSClientTraits
{
    private $mnsClient;

    private function getMnsClient()
    {
        if (null === $this->mnsClient) {
            $configs = config('ram.mns');
            $this->mnsClient = new MnsClient(
                $configs['end_point'] ?? '',
                $configs['access_key_id'] ?? '',
                $configs['access_key_secret'] ?? '',
                null
            );
        }

        return $this->mnsClient;
    }

    private function sendToQueue(string $queueName, array $data, $base64 = true)
    {
        try {
            __LOG_MESSAGE($data, "sendToQueue.params__${queueName}");
            $messageBody = json_encode($data, JSON_UNESCAPED_UNICODE);
            $request = new SendMessageRequest($messageBody);
            $queue = $this->getMnsClient()->getQueueRef($queueName, $base64);
            $response = $queue->sendMessage($request);
            var_dump($response);
            __LOG_MESSAGE($response, 'sendToQueue.response__' . ($response->getMessageId() ?? 'false'));
            return $response->isSucceed() ? $response->getMessageId() : false;
        } catch (\Exception $e) {
            __LOG_MESSAGE($e);
        }

        return false;
    }
}
