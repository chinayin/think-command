<?php

namespace app\example\traits;

use AliyunMNS\Client as MnsClient;
use AliyunMNS\Requests\SendMessageRequest;

trait MNSClientTraits
{
    private $mnsClient;

    private function getMnsClient()
    {
        if (null === $this->mnsClient) {
            $configs = config('mns');
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
