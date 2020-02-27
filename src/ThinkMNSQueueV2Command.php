<?php

namespace think\command;

use AliyunMNS\Client as MnsClient;
use Swoole\Process\Pool;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * ThinkMNSQueueV2Command
 *
 * @author  lei.tian <whereismoney@qq.com>
 * @since   2020-02-27
 * @version 2.0
 */
abstract class ThinkMNSQueueV2Command extends ThinkCommand
{
    /** @var string 队列名称 */
    protected $queueName;
    /** @var string 主题名称 */
    protected $topicName;
    // ----------
    /** @var int 重试到第N次消费,就删除 */
    protected $maxConsumedTimes = 5;
    /** @var int 轮询时间30秒 */
    protected $waitSeconds = 30;
    /** @var int 批次获取消息的数量 (默认是1条 暂时不支持设置多条) */
    protected $numOfReceiveMessages = 1;
    /** @var bool 数据格式 是否是  a=1&b=2格式 */
    protected $queueMessageIsParseStr = false;
    /** @var bool 队列资源是否base64转码   true是需要转码  false是不用转码明文的 */
    protected $queueMessageIsBase64 = true;
    /** @var bool 是否允许动态覆盖队列名称 */
    protected $allowOverrideQueueTopicName = false;
    /** @var bool 是否使用阿里云临时token方案 */
    protected $useStsToken = false;
    // ----------
    /** @var 配置 */
    private $configs;
    /** @var 客户端 */
    private $client;
    /** @var 需要申请临时token的处理 */
    private $stsToken;
    /** @var $receiptHandles */
    private $receiptHandles;

    /**
     * 命令行参数配置
     *
     * @return array|null
     */
    protected function buildCommandDefinition()
    {
        return [
            new Option('queue', null, Option::VALUE_OPTIONAL, 'Override queue name'),
            new Option('topic', null, Option::VALUE_OPTIONAL, 'Override topic name'),
        ];
    }

    /**
     * 动态配置队列主题名称
     */
    protected function dynamicOverrideQueueTopicName()
    {
        if (!$this->allowOverrideQueueTopicName) {
            return;
        }
        $queueName = $this->input->getOption('queue');
        empty($queueName) || $this->setQueueName($queueName);
        $topicName = $this->input->getOption('topic');
        empty($topicName) || $this->setTopicName($topicName);
    }

    /**
     * 主函数
     *
     * @param Input  $input
     * @param Output $output
     */
    protected function main(Input $input, Output $output)
    {
        // 动态配置队列主题名称
        $this->dynamicOverrideQueueTopicName();
        // 显示队列名称
        if (!empty($this->queueName)) {
            $s = "Queue: <info>{$this->queueName}</info>";
            $output->comment($s);
            __LOG_MESSAGE(strip_tags($s));
        }
        if (!empty($this->topicName)) {
            $s = "Topic: <info>{$this->topicName}</info>";
            $output->comment($s);
            __LOG_MESSAGE(strip_tags($s));
        }
        // 使用Swoole\Process\Pool
        if ($this->workerNum > 1) {
            $pool = new Pool($this->workerNum);
            $pool->on("WorkerStart", [$this, 'workerStart']);
            $pool->on("WorkerStop", [$this, 'workerStop']);
            $pool->start();
        } else {
            // 单进程
            $this->messageReceived();
        }
    }

    /**
     * @param Pool $pool
     * @param int  $workerId
     */
    public function workerStart(Pool $pool, int $workerId)
    {
        $workerName = @cli_get_process_title() ?: $this->commandName;
        @$pool->getProcess()->name("swoole $workerName #$workerId");
        __LOG_MESSAGE("Worker#{$workerId} is started");
        $this->output->writeln("Worker#{$workerId} is started");
        $this->messageReceived($workerId);
    }

    /**
     * @param Pool $pool
     * @param int  $workerId
     */
    public function workerStop(Pool $pool, int $workerId)
    {
        __LOG_MESSAGE("Worker#{$workerId} is stopped");
        $this->output->writeln("Worker#{$workerId} is stopped");
    }

    /**
     * 接收消息
     *
     * @param int $workerId
     */
    private function messageReceived(int $workerId = 0)
    {
        $output = $this->output;
        while (true) {
            echo '.';
            try {
                $queue = $this->getMnsClient()->getQueueRef($this->queueName, $this->queueMessageIsBase64);
//                $rsp = $queue->receiveMessage($this->waitSeconds);
                $response = $queue->batchReceiveMessage(new \AliyunMNS\Requests\BatchReceiveMessageRequest($this->numOfReceiveMessages,
                    $this->waitSeconds));
                if (!$response->isSucceed()) {
                    continue;
                }
                $isBase64 = $response->isBase64();
                $messages = $response->getMessages();
                //__LOG_MESSAGE_DEBUG($messages, '$messages');
                foreach ($messages as $message) {
                    $message_id = $message->getMessageId();
                    // 消费次数
                    $consumedTimes = $message->getDequeueCount();
                    $s = "$message_id" . ($consumedTimes > 1 ? "($consumedTimes)" : '') . ' ';
                    $output->write("#$workerId MessageId $s");
                    __LOG_MESSAGE($s, "#$workerId MessageId");
                    unset($s);
                    // 验证消息md5
                    $bodyMD5 = strtoupper(md5(
                        ($isBase64 ? base64_encode($message->getMessageBody()) : $message->getMessageBody())
                    ));
                    if ($bodyMD5 !== $message->getMessageBodyMD5()) {
                        throw new \Exception('MessageBodyMD5_NOT_MATCH');
                    }
                    $receiptHandle = $message->getReceiptHandle();
                    // 处理最大消费失败
                    if ($consumedTimes >= $this->maxConsumedTimes) {
                        $this->triggerMaxConsumedTimes($message);
                        $rsp = $queue->deleteMessage($receiptHandle);
                        $output->warning('MAX_CONSUMED_TIMES');
                        __LOG_MESSAGE('status = MAX_CONSUMED_TIMES', $message_id);
                        continue;
                    }
                    // 获取数组信息
                    $json = $this->getMessageBodyJson($message);
                    // 数据格式错误
                    if (null === $json || false === $json) {
                        $ret = true;
                    } else {
                        // 消费
                        $ret = $this->consume($message_id, $json, $message);
                    }
                    // 消费成功,删除
                    if ($ret) {
                        // ack
                        $rsp = $queue->deleteMessage($receiptHandle);
                        $output->writeln('OK');
                        __LOG_MESSAGE('status = OK', $message_id);
                    } else {
                        $output->warning('FAIL');
                        __LOG_MESSAGE('status = FAIL', $message_id);
                    }
                }
                unset($messages);
            } catch (\AliyunMNS\Exception\QueueNotExistException $e) {
                __LOG_MESSAGE($e);
                $output->error($e->getMessage());
                exit;
            } catch (\AliyunMNS\Exception\MessageNotExistException $e) {
            } catch (\AliyunMNS\Exception\MnsException $e) {
                __LOG_MESSAGE($e);
                $output->error($e->getMessage());
            } catch (\Exception $e) {
                __LOG_MESSAGE($e);
                $output->error($e->getMessage());
            }
        }
    }

    /**
     * 消息消费
     *
     * @param string $message_id
     * @param array  $json
     * @param        $message
     *
     * @return mixed
     */
    abstract protected function consume(string $message_id, array $json, $message);

    /**
     * 获取消息的数组信息
     *
     * @param $message
     *
     * @return array|mixed
     */
    protected function getMessageBodyJson($message)
    {
        if ($this->queueMessageIsParseStr) {
            $json = [];
            parse_str($message->getMessageBody(), $json);
        } else {
            $json = json_decode($message->getMessageBody(), true);
        }
        return $json;
    }

    /**
     * 处理重试错误次数过多
     *
     * @param mixed $message
     */
    protected function triggerMaxConsumedTimes($message)
    {
        $json = $this->getMessageBodyJson($message);
        __LOG_MESSAGE_ERROR($json, sprintf('triggerMaxConsumedTimes___%s___%s',
            $message->getMessageId(),
            $message->getDequeueCount()
        ));
        unset($json);
    }

    /**
     * 获取Client
     *
     * @return MnsClient
     * @throws \Exception
     */
    protected function getMnsClient()
    {
        // 非临时token的客户端
        if (!$this->useStsToken) {
            if (null === $this->client) {
                $configs = $this->getConfigs();
                if (!empty($configs)) {
                    $this->client = new MnsClient(
                        $configs['end_point'] ?? '',
                        $configs['access_key_id'] ?? '',
                        $configs['access_key_secret'] ?? '',
                        null
                    );
                }
            }
        } else { // 临时token方案
            if (// 如果客户端未初始化
                null === $this->client ||
                // 不存在临时token
                null === $this->stsToken ||
                // 临时token 过期时间距离现在不足2分钟就重新申请新的
                ((strtotime($this->stsToken['ExpireTime']) - time()) <= 2 * 60)
            ) {
                // 申请临时token
                $this->stsToken = $this->queryTokenForMnsQueue();
                $this->client = new MnsClient(
                    $this->mnsStsToken['EndPoint'] ?? '',
                    $this->mnsStsToken['AccessKeyId'] ?? '',
                    $this->mnsStsToken['AccessKeySecret'] ?? '',
                    $this->mnsStsToken['SecurityToken'] ?? ''
                );
            }
        }
        return $this->client;
    }

    /**
     * 申请临时token.
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

    /**
     * 获取配置
     *
     * @return mixed
     * @throws \Exception
     */
    protected function getConfigs()
    {
        if (null === $this->configs) {
            $this->configs = config('mns');
            if (empty($this->configs)) {
                throw new \Exception('config[mns] not found.');
            }
        }

        return $this->configs;
    }

    /**
     * @param $configs
     */
    protected function setConfigs($configs)
    {
        $this->configs = $configs;
    }

    /**
     * @return mixed
     */
    public function getQueueName()
    {
        return $this->queueName;
    }

    /**
     * @param mixed $queueName
     */
    public function setQueueName(string $queueName): void
    {
        $this->queueName = $queueName;
    }

    /**
     * @return mixed
     */
    public function getTopicName()
    {
        return $this->topicName;
    }

    /**
     * @param mixed $topicName
     */
    public function setTopicName(string $topicName): void
    {
        $this->topicName = $topicName;
    }

}