<?php

namespace think\command;

use Godruoyi\Snowflake\Snowflake;
use Swoole\Process\Pool;
use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Debug;

/**
 * ThinkCommand.
 *
 * @author  lei.tian <whereismoney@qq.com>
 * @since   2018-06-06
 * @version 2.1
 */
abstract class ThinkCommand extends Command
{
    /** @var string 命令名称 */
    protected $commandName;
    /** @var string 命令描述 */
    protected $commandDescription;
    /** @var bool 是否debug模式 */
    protected $isDebug = false;
    /** @var bool 是否force */
    private $isForce = false;
    /** @var string 唯一序列 */
    private $serialId;
    /** @var string 启动版本 */
    private $serialVersion;
    /** @var array 错误输出 */
    private $errors = [];
    /** @var array 警告输出 */
    private $warns = [];
    /** @var int worker数(配合SwoolePool建议>0使用) */
    protected $workerNum = 0;
    /** @var int pid */
    private $pid = 0;

    protected function getSerialVersion(): string
    {
        return $this->serialVersion;
    }

    protected function getSerialId(): string
    {
        return $this->serialId;
    }

    protected function isDebugMode(): bool
    {
        return $this->isDebug;
    }

    protected function isForceMode(): bool
    {
        return $this->isForce;
    }

    /**
     * 打印任务头
     */
    protected function printSerialVersion()
    {
        $s = [
            str_pad('', 50, '='),
            sprintf(
                'Env: <info>%s</info> <highlight>%s</highlight>',
                \think\Config::get('app_status'),
                (IS_PRODUCTION ? '(PROD)' : '')
            ),
            sprintf(
                'Region: <info>%s</info> <highlight>%s</highlight>',
                !empty(DEPLOY_REGION_ID) ? DEPLOY_REGION_ID : 'unknown',
                (DEPLOY_IS_ABROAD_ZONE ? '(Abroad)' : '')
            ),
            sprintf('SerialVersion: <info>%s</info>', $this->getSerialVersion()),
            sprintf(
                'Options: debug => <info>%s</info>, force => <info>%s</info>',
                $this->isDebug ? 'true' : 'false',
                $this->isForce ? 'true' : 'false'
            ),
            sprintf('TimeStamp: <info>%s</info>', date('c')),
            str_pad('', 50, '='),
        ];
        $s = implode(PHP_EOL, $s);
        $this->output->comment($s);
        __LOG_MESSAGE(PHP_EOL . strip_tags($s));
        unset($s);
    }

    /**
     * 程序执行完成
     */
    protected function printExecutionCompleted()
    {
        $s = sprintf(
            'done. { <info>%s</info>, seconds => <info>%s</info>, memory_get_usage => <info>%s</info>, memory_get_peak_usage => <info>%s</info>, pid => <info>%s</info> }',
            date('c'),
            Debug::getUseTime(1),
            Debug::getUseMem(1),
            Debug::getUsePeakMem(1),
            $this->pid
        );
        $this->output->writeln($s);
        __LOG_MESSAGE(PHP_EOL . strip_tags($s));
        unset($s);
    }

    /**
     * 设置日志保存路径
     */
    protected function setLogPath()
    {
        $log_path = LOG_PATH . str_replace(':', DS, $this->commandName) . DS;
        Config::set('log.path', $log_path);
        \think\Log::write($log_path);
    }

    /**
     * 解析option
     *
     * @param Input $input
     */
    protected function setOptions(Input $input)
    {
        if (true === $input->hasParameterOption(['--debug'])) {
            $this->isDebug = true;
        }
        define('IS_DEBUG_CONSOLE', $this->isDebug);
        if (true === $input->hasParameterOption(['--force'])) {
            $this->isForce = true;
        }
        if (true === $input->hasParameterOption(['--thread'])) {
            $this->workerNum = (int)$input->getOption('thread');
        }
    }

    /**
     * 配置
     */
    protected function configure()
    {
        parent::configure();
        $this->setName($this->commandName)->setDescription($this->commandDescription);
        $this->serialId = uniqid();
        $this->serialVersion = str_replace(':', '-', $this->getName()) . '-' . $this->serialId;
        $this->pid = getmypid();
        $defaultDefinition = [
            new Option('debug', 'd', Option::VALUE_OPTIONAL, 'is debug mode?', false),
            new Option('force', 'f', Option::VALUE_OPTIONAL, 'is force mode?', false),
            new Option('thread', null, Option::VALUE_OPTIONAL, 'ThreadPool workerNum', 0),
        ];
        $definitions = array_merge(
            $defaultDefinition,
            $this->buildCommandDefinition()
        );
        // 命令行参数配置(数组)
        $this->setDefinition($definitions);
        unset($definitions);
        // 命令行参数配置(可覆盖)
        $this->setCommandDefinition();
    }

    /**
     * 任务运行
     *
     * @param Input $input
     * @param Output $output
     *
     * @return int|null
     */
    protected function execute(Input $input, Output $output): ?int
    {
        // 设置日志路径
        $this->setLogPath();
        // 解析配置
        $this->setOptions($input);
        // 打印任务头
        $this->printSerialVersion();
        // main之前
        $this->onMainBeforeCallback($input, $output);
        // 主函数
        $statusCode = $this->main($input, $output);
        // main之后
        $this->onMainAfterCallback($input, $output);
        // 输出错误信息
        ($statusCode !== true && $statusCode !== 0) && $this->handleErrorWarn();
        // done
        $this->printExecutionCompleted();
        return $statusCode;
    }

    /**
     * 任务执行前
     *
     * @param Input $input
     * @param Output $output
     */
    protected function onMainBeforeCallback(Input $input, Output $output)
    {
    }

    /**
     * 任务执行后
     *
     * @param Input $input
     * @param Output $output
     */
    protected function onMainAfterCallback(Input $input, Output $output)
    {
    }

    /**
     * 命令行参数配置(待废弃)
     *
     * @deprecated
     */
    protected function setCommandDefinition()
    {
    }

    /**
     * 命令行参数配置(通过数组定义) -- 推荐使用
     * return [
     * new Argument('namespace', InputArgument::OPTIONAL, 'The namespace name'),
     * new Option('raw', null, InputOption::VALUE_NONE, 'To output raw command list')
     * ];
     */
    protected function buildCommandDefinition(): array
    {
        return [];
    }

    /**
     * 执行命令主函数
     *
     * @param Input $input
     * @param Output $output
     *
     * @return mixed
     */
    abstract protected function main(Input $input, Output $output);

    /**
     * 启动swoole pool
     *
     * @return bool
     * @throws \Exception
     */
    protected function startSwoolePoolWorkers(): bool
    {
        if (empty($this->workerNum) || !is_integer($this->workerNum)) {
            throw new \Exception('workerNum must integer');
        }
        if ($this->workerNum < 2) {
            throw new \Exception('workerNum must greater than 1');
        }
        //
        $this->initializeSnowflake();
        //
        $pool = new Pool($this->workerNum);
        $pool->on("WorkerStart", [$this, 'onWorkerStart']);
        $pool->on("WorkerStop", [$this, 'onWorkerStop']);
        return $pool->start();
    }

    /**
     * swoole pool 工作进程
     *
     * @param int $workerId
     *
     * @throws \Exception
     */
    protected function onWorkerCallback(int $workerId = 0)
    {
        throw new \LogicException('You must override the onWorkerCallback() method in the concrete command class.');
    }

    /**
     * @param Pool $pool
     * @param int $workerId
     *
     * @throws \Exception
     */
    public function onWorkerStart(Pool $pool, int $workerId)
    {
        //mac os不支持进程重命名
        if (PHP_OS !== 'Darwin') {
            $workerName = @cli_get_process_title() ?: $this->commandName;
            @$pool->getProcess()->name("swoole $workerName {$this->pid}#$workerId");
        }
        __LOG_MESSAGE("Worker#$workerId is started");
        $this->output->writeln("Worker#$workerId is started");
        //
        $this->onWorkerCallback($workerId);
    }

    /**
     * @param Pool $pool
     * @param int $workerId
     */
    public function onWorkerStop(Pool $pool, int $workerId)
    {
        __LOG_MESSAGE("Worker#$workerId is stopped");
        $this->output->writeln("Worker#$workerId is stopped");
    }

    /**
     * 测试输出内容
     *
     * @param Output $output
     */
    protected function testOutputStyles(Output $output)
    {
        foreach (
            [
                'info',
                'error',
                'comment',
                'question',
                'highlight',
                'warning',
            ] as $style
        ) {
            $output->{$style}($style);
        }
    }

    /**
     * 控制台输出,记录日志.
     *
     * @param       $format
     * @param mixed ...$args
     */
    protected function print($format, ...$args)
    {
        $msg = count($args) > 0 ? sprintf($format, ...$args) : $format;
        __LOG_MESSAGE($msg);
        $this->output->write($msg);
    }

    protected function println($format, ...$args)
    {
        $msg = count($args) > 0 ? sprintf($format, ...$args) : $format;
        __LOG_MESSAGE($msg);
        $this->output->writeln($msg);
    }

    /**
     * 添加错误信息.
     *
     * @param      $msg
     * @param null $key
     */
    protected function addError($msg, $key = null)
    {
        $this->errors[] = (null === $key ? '' : "key = {$key}, ") . $msg;
    }

    protected function getErrors(): array
    {
        return $this->errors;
    }

    protected function getErrorsString(): string
    {
        return implode(PHP_EOL, $this->errors);
    }

    protected function printErrorsMessage()
    {
        if (empty($this->getErrors())) {
            return;
        }
        __LOG_MESSAGE(PHP_EOL . $this->getErrorsString(), '$errors');
        $this->output->error($this->getErrorsString());
    }

    /**
     * 添加警告信息.
     *
     * @param      $msg
     * @param null $key
     */
    protected function addWarn($msg, $key = null)
    {
        $this->warns[] = (null === $key ? '' : "key = {$key}, ") . $msg;
    }

    protected function getWarns(): array
    {
        return $this->warns;
    }

    protected function getWarnsString(): string
    {
        return implode(PHP_EOL, $this->warns);
    }

    protected function printWarnsMessage()
    {
        if (empty($this->getWarns())) {
            return;
        }
        __LOG_MESSAGE(PHP_EOL . $this->getWarnsString(), '$warns');
        $this->output->warning($this->getWarnsString());
    }

    protected function handleErrorWarn()
    {
        $this->printErrorsMessage();
        $this->printWarnsMessage();
    }

    private $snowflakes = [];

    private function initializeSnowflake()
    {
        if ($this->workerNum <= 0) {
            return;
        }
        for ($i = 0; $i < $this->workerNum; $i++) {
            $datacenter = (int)($i / 32);
            $workerId = $i % 32;
            $this->snowflakes[$i] = new Snowflake($datacenter, $workerId);
        }
    }

    /**
     * @param int $workerId
     *
     * @return Snowflake
     */
    protected function snowflake(int $workerId = 0): Snowflake
    {
        return $this->snowflakes[$workerId];
    }
}
