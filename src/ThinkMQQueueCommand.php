<?php

namespace think\command;

use MQ\MQClient;
use think\Config;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * ThinkMQQueueCommand
 *
 * @author  lei.tian <whereismoney@qq.com>
 * @since   2020-02-18
 * @version 1.0
 */
abstract class ThinkMQQueueCommand extends ThinkCommand
{
    /** @var string 实例ID */
    protected $instanceId;
    /** @var string 组ID */
    protected $groupId;
    /** @var string 主题名称 */
    protected $topicName;
    /** @var string 主题tag */
    protected $messageTag;
    // ----------
    /** @var int 重试到第N次消费,就删除 */
    protected $maxConsumedTimes = 5;
    /** @var int 轮询时间30秒 */
    protected $waitSeconds = 30;
    /** @var int 批次获取消息的数量 (默认是1条 暂时不支持设置多条) */
    protected $numOfReceiveMessages = 1;
    /** @var bool 数据格式 是否是  a=1&b=2格式 */
    protected $queueMessageIsParseStr = false;
    /** @var bool 是否允许动态覆盖队列名称 */
    protected $allowOverrideTopicName = false;
    /** @var bool 是否使用阿里云临时token方案 */
    protected $useStsToken = false;
    // ----------
    /** @var mixed 配置 */
    private $configs;
    /** @var mixed 客户端 */
    private $client;
    /** @var mixed 消费者 */
    private $consumer;
    /** @var mixed 需要申请临时token的处理 */
    private $stsToken;
    /** @var mixed $receiptHandles */
    private $receiptHandles;

    /**
     * 命令行参数配置
     *
     * @return array|null
     */
    protected function buildCommandDefinition()
    {
        return [
            new Option('topic', null, Option::VALUE_OPTIONAL, 'Override topic name'),
        ];
    }

    /**
     * 动态配置队列主题名称
     */
    protected function dynamicOverrideTopicName()
    {
        if (!$this->allowOverrideTopicName) {
            return;
        }
        $topicName = $this->input->getOption('topic');
        empty($topicName) or $this->setTopicName($topicName);
    }

    /**
     * 主函数
     *
     * @param Input $input
     * @param Output $output
     *
     * @throws \Exception
     */
    protected function main(Input $input, Output $output)
    {
        // 动态配置队列主题名称
        $this->dynamicOverrideTopicName();
        // 显示队列名称
        foreach ([
                     "WorkerNum: <info>{$this->workerNum}</info>",
                     "InstanceId: <info>{$this->getInstanceId()}</info>",
                     "GroupId: <info>{$this->getGroupId()}</info>",
                     "Topic: <info>{$this->getTopicName()}</info>",
                     "Tag: <info>{$this->getMessageTag()}</info>",
                 ] as $s) {
            $output->comment($s);
            __LOG_MESSAGE(strip_tags($s));
        }
        // 使用Swoole\Process\Pool
        if ($this->workerNum > 1) {
            $this->startSwoolePoolWorkers();
        } else {
            // 单进程
            $this->onWorkerCallback();
        }
    }

    /**
     * 接收消息
     *
     * @param int $workerId
     */
    public function onWorkerCallback(int $workerId = 0)
    {
        $output = $this->output;
        while (true) {
            echo '.';
            try {
                // 长轮询表示如果topic没有消息则请求会在服务端挂住3s，3s内如果有消息可以消费则立即返回
                $messages = $this->getMQConsumer()->consumeMessage(
                // 一次最多消费(最多可设置为16条)
                    $this->numOfReceiveMessages,
                    // 长轮询时间(最多可设置为30秒)
                    $this->waitSeconds
                );
                foreach ($messages as $message) {
                    $message_id = $message->getMessageId();
                    // 消费次数
                    $consumedTimes = $message->getConsumedTimes();
                    $s = "$message_id" . ($consumedTimes > 1 ? "($consumedTimes)" : '') . ' ';
                    $output->write("#$workerId MessageId $s");
                    __LOG_MESSAGE($s, "#$workerId MessageId");
                    unset($s);
                    // 验证消息md5(tcp端发来的不会有md5)
                    if (!empty($message->getMessageBodyMD5()) &&
                        strtoupper(md5($message->getMessageBody())) !== $message->getMessageBodyMD5()) {
                        throw new \Exception('MessageBodyMD5_NOT_MATCH');
                    }
                    $receiptHandle = $message->getReceiptHandle();
                    // 处理最大消费失败
                    if ($consumedTimes >= $this->maxConsumedTimes) {
                        $this->triggerMaxConsumedTimes($message);
                        $rsp = $this->getMQConsumer()->ackMessage([$receiptHandle]);
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
                        $ret = $this->consume($message_id, $json, $message->getProperties() ?? [], $message, $workerId);
                    }
                    // 消费成功,删除
                    if ($ret) {
                        // ack
                        $rsp = $this->getMQConsumer()->ackMessage([$receiptHandle]);
                        $output->writeln('OK');
                        __LOG_MESSAGE('status = OK', $message_id);
                    } else {
                        $output->warning('FAIL');
                        __LOG_MESSAGE('status = FAIL', $message_id);
                    }
                }
                unset($messages);
            } catch (\MQ\Exception\TopicNotExistException $e) {
                __LOG_MESSAGE($e);
                $output->error($e->getMessage());
                exit;
            } catch (\MQ\Exception\MessageNotExistException $e) {
            } catch (\MQ\Exception\AckMessageException $e) {
                __LOG_MESSAGE($e);
                $output->error($e->getMessage());
            } catch (\MQ\Exception\MQException $e) {
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
     * @param array  $properties
     * @param        $message
     * @param int    $workerId
     *
     * @return mixed
     */
    abstract protected function consume(
        string $message_id,
        array $json,
        array $properties,
        $message,
        int $workerId = 0
    );

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
            $message->getConsumedTimes()
        ));
        unset($json);
    }

    /**
     * 获取Client
     *
     * @return MQClient
     * @throws \Exception
     */
    protected function getMQClient()
    {
        // 非临时token的客户端
        if (!$this->useStsToken) {
            if (null === $this->client) {
                $configs = $this->getConfigs();
                if (!empty($configs)) {
                    $this->client = new MQClient(
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
                $this->isStsTokenExpired()
            ) {
                // 申请临时token
                $this->stsToken = $this->queryTokenForMqQueue();
                $this->client = new MQClient(
                    $this->stsToken['EndPoint'] ?? '',
                    $this->stsToken['AccessKeyId'] ?? '',
                    $this->stsToken['AccessKeySecret'] ?? '',
                    $this->stsToken['SecurityToken'] ?? ''
                );
            }
        }

        return $this->client;
    }

    /**
     * @return \MQ\MQConsumer
     * @throws \Exception
     */
    public function getMQConsumer()
    {
        if (null === $this->consumer) {
            $instanceId = $this->getInstanceId();
            $groupId = $this->getGroupId();
            $topic = $this->getTopicName();
            $messageTag = $this->getMessageTag();
            $this->consumer = $this->getMQClient()->getConsumer($instanceId, $topic, $groupId, $messageTag);
        }
        return $this->consumer;
    }

    /**
     * 判断sts token是否过期
     * @return bool
     */
    private function isStsTokenExpired()
    {
        $exp = $this->stsToken['ExpireTime'] ?? '';
        return empty($exp) ? true : ((strtotime($exp) - time()) <= 2 * 60);
    }

    /**
     * 申请临时token.
     * 需要的地方临时处理.
     */
    protected function queryTokenForMqQueue()
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
            $this->configs = Config::get('ram.mq');
            if (empty($this->configs)) {
                throw new \Exception('config[ram.mq] not found . ');
            }
        }

        return $this->configs;
    }

    /**
     * @return mixed
     */
    public function getTopicName()
    {
        return $this->topicName;
    }

    /**
     * @param string $topicName
     */
    public function setTopicName(string $topicName): void
    {
        $this->topicName = $topicName;
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function getInstanceId()
    {
        if (null === $this->instanceId) {
            $this->instanceId = $this->getConfigs()['instance_id'];
        }
        return $this->instanceId;
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function getGroupId()
    {
        if (null === $this->groupId) {
            $this->groupId = $this->getConfigs()['group_id'];
        }
        return $this->groupId;
    }

    /**
     * @return mixed
     */
    public function getMessageTag()
    {
        return $this->messageTag;
    }

}
