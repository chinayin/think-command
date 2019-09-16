<?php

namespace think\command;

//use AlibabaCloud\Client\Exception\ServerException;
//use AlibabaCloud\Dybaseapi\MNS\MnsClient;
//use AlibabaCloud\Dybaseapi\MNS\Requests\BatchDeleteMessage;
//use AlibabaCloud\Dybaseapi\MNS\Requests\BatchReceiveMessage;
use AliyunMNS\Client as MnsClient;
use AliyunMNS\Exception\MnsException;
use think\console\Input;
use think\console\Output;

/**
 * BaseMnsQueue.
 *
 * @author  lei.tian <whereismoney@qq.com>
 * @since   2018-06-06
 * @version 2.0
 */
abstract class ThinkMnsQueueCommand extends ThinkCommand
{
    // 轮训时间30秒
    protected $waitSeconds = 30;
    // 重试到第N次消费,就删除 (这个部分使用,单独的删除逻辑 走下面自定义)
    protected $maxDequeueCount = 5;
    // 批次获取消息的数量 (默认是1条 暂时不支持设置多条)
    protected $numOfReceiveMessages = 1;
    // 队列资源是否base64转码   true是需要转码  false是不用转码明文的
    protected $mnsQueueIsBase64 = true;
    // 数据格式 是否是  a=1&b=2格式
    protected $mnsQueueMessageIsParseStr = false;
    // 队列名称
    protected $queueName;
    // 主题名称
    protected $topicName;
    // 是否使用阿里云临时token方案
    protected $mnsIsUseStsToken = false;
    // 需要申请mns临时token的处理
    protected $mnsStsToken;
    // mns配置
    private $mnsConfigs;
    // mns客户端
    private $mnsClient;
    // 已收入的队列信息 (这里的信息会删除掉)
    private $receiptHandles;

    // 获取mns配置
    protected function getMnsConfigs()
    {
        if (null === $this->mnsConfigs) {
            $this->mnsConfigs = config('mns');
            if (empty($this->mnsConfigs)) {
                throw new \Exception('config[mns] not found.');
            }
        }

        return $this->mnsConfigs;
    }

    protected function setMnsConfigs($mnsConfigs)
    {
        $this->mnsConfigs = $mnsConfigs;
    }

    // 获取mnsClient
    protected function getMnsClient()
    {
        // 非临时token的客户端
        if (!$this->mnsIsUseStsToken) {
            if (null === $this->mnsClient) {
                $configs = $this->getMnsConfigs();
                if (!empty($configs)) {
                    $this->mnsClient = new MnsClient(
                        $configs['end_point'] ?? '',
                        $configs['access_id'] ?? '',
                        $configs['access_key'] ?? '',
                        null
                    );
                }
            }
        } else { // 临时token方案
            if (// 如果客户端未初始化
                null === $this->mnsClient ||
                // 不存在临时token
                null === $this->mnsStsToken ||
                // 临时token 过期时间距离现在不足2分钟就重新申请新的
                ((strtotime($this->mnsStsToken['ExpireTime']) - time()) <= 2 * 60)
            ) {
                // 申请临时token
                $this->mnsStsToken = $this->queryTokenForMnsQueue();
                $this->mnsClient = new MnsClient(
                    $this->mnsStsToken['EndPoint'] ?? '',
                    $this->mnsStsToken['AccessKeyId'] ?? '',
                    $this->mnsStsToken['AccessKeySecret'] ?? '',
                    $this->mnsStsToken['SecurityToken'] ?? ''
                );
            }
        }

        return $this->mnsClient;
    }

    /**
     * 主函数.
     *
     * @param Input  $input
     * @param Output $output
     */
    protected function main(Input $input, Output $output)
    {
        //显示队列名称
        empty($this->queueName) || $output->comment(sprintf('Queue: <info>%s</info>', $this->queueName));
        empty($this->topicName) || $output->comment(sprintf('Topic: <info>%s</info>', $this->topicName));

        while (true) {
            echo '.';

            try {
                /** 2019-03-27 老版sdk 官方推荐使用 */
                $queue = $this->getMnsClient()->getQueueRef($this->queueName, $this->mnsQueueIsBase64);
                $rsp = $queue->receiveMessage($this->waitSeconds);
                // 2019-03-27 这里适配新版写法 重新做一下 纯粹是为了适配而生
                $mnsResponse = [
                    'Message' => [
                        'MessageId' => $rsp->getMessageId(),
                        'MessageBodyMD5' => $rsp->getMessageBodyMD5(),
                        'MessageBody' => $rsp->getMessageBody(),
                        'ReceiptHandle' => $rsp->getReceiptHandle(),
                        'EnqueueTime' => $rsp->getEnqueueTime(),
                        'FirstDequeueTime' => $rsp->getFirstDequeueTime(),
                        'NextVisibleTime' => $rsp->getNextVisibleTime(),
                        'DequeueCount' => $rsp->getDequeueCount(),
                        'Priority' => $rsp->getPriority(),
                    ]
                ];
                /* 2019-03-27 php-sdk 新版写法 官方有bug 等修复了再使用
                $mnsRequest = new BatchReceiveMessage(1, $this->waitSeconds);
                $mnsRequest->setQueueName($this->queueName);
                $mnsResponse = $this->getMnsClient()->sendRequest($mnsRequest);
                 */
                $output->write('#');
                //{"Message":{"MessageId":"D7442E19B016DDE8-1-1696C61618D-200000001","MessageBodyMD5":"9E6A284D2F2B0CFF6F70B5C69ADBE253","MessageBody":"eyJtb2RlbCI6ImFjY291bnQiLCJpZCI6MTIzfQ==","ReceiptHandle":"1-ODU4OTkzNDU5My0xNTUyMzAyMDMzLTEtOA==","EnqueueTime":"1552301515149","FirstDequeueTime":"1552301532389","NextVisibleTime":"1552302033000","DequeueCount":"7","Priority":"8"}}
                // 判断过来的数据是否二维数组
                $messages = ($mnsResponse && isset($mnsResponse['Message'])) ? (
                count($mnsResponse['Message']) == count($mnsResponse['Message'], 1) ?
                    [$mnsResponse['Message']] : $mnsResponse['Message']
                ) : [];
//                __LOG_MESSAGE_DEBUG($messages, '$messages');
                foreach ($messages as $message) {
                    $message_id = $message['MessageId'];
                    $output->write(sprintf("msg_id: %s\t", $message_id));
                    __LOG_MESSAGE($message_id, 'msg_id');
                    // 比对摘要，防止消息被截断或发生错误
                    // 对于未base64的消息 这里需要特殊处理 TODO
//                    $bodyMD5 = strtoupper(md5($message['MessageBody']));
                    // 2019-03-27 这里的md5 在新老版本有差异 下面是先按照老版本的来
                    $bodyMD5 = strtoupper(md5(
                        ($this->mnsQueueIsBase64 ? base64_encode($message['MessageBody']) : $message['MessageBody'])
                    ));
                    if ($bodyMD5 !== $message['MessageBodyMD5']) {
                        throw new \Exception('MessageBodyMD5_NOT_MATCH');
                    }
                    $receiptHandle = $message['ReceiptHandle'];
                    // 重试次数
                    $dequeueCount = $message['DequeueCount'];
                    $output->write(($dequeueCount > 1 ? $dequeueCount : ' ') . "\t");
                    __LOG_MESSAGE($dequeueCount, 'dequeueCount__' . $message_id);
                    // 消费次数太多了,就不处理这个数据了,直接删掉
                    if ($dequeueCount >= $this->maxDequeueCount) {
                        // 处理最大消费失败
                        $this->handleMaxDequeue($message);
                        /**
                         *  新版sdk
                         * $deleteRequest = new BatchDeleteMessage($this->queueName, [$receiptHandle]);
                         * $this->getMnsClient()->sendRequest($deleteRequest);.
                         */
                        /** 老版sdk */
                        $res = $queue->deleteMessage($receiptHandle);
                        $output->warning('MAX_DEQUEUE');
                        __LOG_MESSAGE('MAX_DEQUEUE', "status__${message_id}");

                        continue;
                    }
                    // 获取数组信息
                    $json = $this->getMessageBodyJson($message);
                    // 数据格式错误
                    if (null === $json || false === $json) {
                        $ret = true;
                    } else {
                        // 消费
                        $ret = $this->handle($json, $message_id);
                    }
                    // 消费成功,删除
                    if ($ret) {
                        /**
                         *  新版sdk
                         * $deleteRequest = new BatchDeleteMessage($this->queueName, [$receiptHandle]);
                         * $this->getMnsClient()->sendRequest($deleteRequest);.
                         */
                        /** 老版sdk */
                        $res = $queue->deleteMessage($receiptHandle);
                        $output->writeln('OK');
                        __LOG_MESSAGE('OK', "status__${message_id}");
                    } else {
                        $output->warning('FAIL');
                        __LOG_MESSAGE('FAIL', "status__${message_id}");
                    }
                }
                unset($messages);
            } catch (MnsException $e) {    // ServerException
                // 如果是没有消息 跳过不处理异常
                if (404 == $e->getCode()) {
                } else {
                    __LOG_MESSAGE($e);
                    $output->error($e->getMessage());
                }
            } catch (\Exception $e) {
                __LOG_MESSAGE($e);
                $output->error($e->getMessage());
            }
        }
    }

    /**
     * 处理重试错误次数过多.
     *
     * @param mixed $message
     */
    protected function handleMaxDequeue(array $message)
    {
        $json = $this->getMessageBodyJson($message);

        try {
//            $updated = [
//                'msg_id' => $message['MessageId'],
//                'dequeued_count' => $message['DequeueCount'],
//                'request_id' => $json['request_id'] ?? '',
//                'requested_at' => isset($message['PublishTime']) ? $message('Y-m-d H:i:s', $message['PublishTime'] / 1000) : null,
//                'target' => json_encode($json, JSON_UNESCAPED_UNICODE),
//                'package' => json_encode($message, JSON_UNESCAPED_UNICODE),
//            ];
        } catch (\Exception $e) {
            __LOG_MESSAGE($e);
        }
        // 写个日志
        __LOG_MESSAGE_ERROR($json, 'handleMaxDequeue___' . $message['MessageId'] . '___' . $message['DequeueCount']);
        unset($json);
    }

    /**
     * 获取消息的数组信息.
     *
     * @param $message
     *
     * @return array|mixed
     */
    protected function getMessageBodyJson(array $message)
    {
        // 新版sdk
//        $msg = $this->mnsQueueIsBase64 ? base64_decode($message['MessageBody']) : $message['MessageBody'];
        // 老版sdk
        $msg = $message['MessageBody'];
        if ($this->mnsQueueMessageIsParseStr) {
            $json = [];
            parse_str($msg, $json);
        } else {
            $json = json_decode($msg, true);
        }
        unset($msg);

        return $json;
    }

    /**
     * 消息消费.
     *
     * @param mixed $message_id
     */
    abstract protected function handle(array $json, $message_id);

    /**
     * 申请mns临时token.
     * 需要的地方临时处理.
     */
    protected function queryTokenForMnsQueue()
    {
        return [
            'EndPoint' => '',
            'AccessKeyId' => '',
            'AccessKeySecret' => '',
            'SecurityToken' => '',
            'ExpireTime' => '',
            'CreateTime' => '',
        ];
    }
}
