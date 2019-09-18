<?php

namespace think\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * ThinkCommand.
 *
 * @author  lei.tian <whereismoney@qq.com>
 * @since   2018-06-06
 * @version 2.0
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
    protected $isForce = false;
    /** @var string 唯一序列 */
    private $serialId;
    /** @var string 启动版本 */
    private $serialVersion;
    /** @var array 错误输出 */
    private $errors = [];
    /** @var array 警告输出 */
    private $warns = [];

    protected function getSerialVersion()
    {
        return $this->serialVersion;
    }

    protected function getSerialId()
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

    /** 打印任务头 */
    protected function printSerialVersion(Input $input, Output $output)
    {
        $s = [
            str_pad('', 50, '='),
            sprintf(
                'Env: <info>%s</info> <highlight>%s</highlight>',
                \think\Config::get('app_status'),
                (IS_PRODUCTION ? '(PROD)' : '')
            ),
            sprintf('SerialVersion: <info>%s</info>', $this->getSerialVersion()),
            sprintf(
                'Options: debug - <info>%s</info>, force - <info>%s</info>',
                $this->isDebug ? 'true' : 'false',
                $this->isForce ? 'true' : 'false'
            ),
            sprintf('TimeStamp: <info>%s</info>', date('Y-m-d H:i:s')),
            str_pad('', 50, '='),
        ];
        $s = implode(PHP_EOL, $s);
        $output->comment($s);
        __LOG_MESSAGE(PHP_EOL . strip_tags($s));
        unset($s);
    }

    /** 设置日志保存路径 */
    protected function setLogPath()
    {
        $log_path = LOG_PATH . str_replace(':', DS, $this->commandName) . DS;
        config('log.path', $log_path);
        \think\Log::write($log_path);
    }

    /** 解析option */
    protected function setOptions(Input $input)
    {
        if (true === $input->hasParameterOption(['--debug'])) {
            $this->isDebug = true;
        }
        define('IS_DEBUG_CONSOLE', $this->isDebug);
        if (true === $input->hasParameterOption(['--force'])) {
            $this->isForce = true;
        }
    }

    /** 配置 */
    protected function configure()
    {
        parent::configure();
        $this->setName($this->commandName)->setDescription($this->commandDescription);
        $this->serialId = uniqid();
        $this->serialVersion = str_replace(':', '-', $this->getName()) . '-' . $this->serialId;
        $this->addOption('debug', 'd', Option::VALUE_OPTIONAL, 'is debug mode?', false);
        $this->addOption('force', 'f', Option::VALUE_OPTIONAL, 'is force mode?', false);
        // 命令行参数配置(数组)
        is_array($this->buildCommandDefinition()) && $this->setDefinition($this->buildCommandDefinition());
        // 命令行参数配置
        $this->setCommandDefinition();
    }

    /** 任务运行 */
    protected function execute(Input $input, Output $output)
    {
        // 设置日志路径
        $this->setLogPath();
        // 解析配置
        $this->setOptions($input);
        // 打印任务头
        $this->printSerialVersion($input, $output);
        // 主函数
        $this->main($input, $output);
        // 输出错误信息
        $this->handleErrorWarn();
        $this->output->writeln(PHP_EOL . 'done.');
    }

    /** 命令行参数配置 */
    protected function setCommandDefinition() { }

    /**
     * 命令行参数配置(通过数组定义) -- 推荐使用
     * return [
     * new Argument('namespace', InputArgument::OPTIONAL, 'The namespace name'),
     * new Option('raw', null, InputOption::VALUE_NONE, 'To output raw command list')
     * ];
     *
     * @return null|array
     */
    protected function buildCommandDefinition() { }

    /** 执行命令主函数 */
    abstract protected function main(Input $input, Output $output);

    /**
     * 测试输出内容.
     */
    protected function testOutputStyles(Output $output)
    {
        foreach ([
                     'info',
                     'error',
                     'comment',
                     'question',
                     'highlight',
                     'warning',
                 ] as $style) {
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
        $this->errors[] = (null === $key ? '' : ('$key = ' . $key . ', ')) . $msg;
    }

    protected function getErrors()
    {
        return $this->errors;
    }

    protected function getErrorsString()
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
        $this->warns[] = (null === $key ? '' : ('$key = ' . $key . ', ')) . $msg;
    }

    protected function getWarns()
    {
        return $this->warns;
    }

    protected function getWarnsString()
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
}
