<?php

namespace app\example;

use think\command\ThinkCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * 测试命令行
 * php think test:command 10 --delay=3 --debug
 */
final class Command extends ThinkCommand
{
    // 命令行配置
    protected $commandName = 'example:Command';
    protected $commandDescription = 'command';

    // 命令行参数配置
    protected function buildCommandDefinition(): array
    {
        return [
            new Argument('value', Argument::REQUIRED, 'value'),
            new Option('delay', null, Option::VALUE_REQUIRED, 'delay'),
        ];
    }

    // 主入口
    protected function main(Input $input, Output $output)
    {
        $value = $input->getArgument('value');
        $delay = $input->getOption('delay');
        $this->println('value = %s, delay = %s', $value, $delay);
        foreach ([1, 3, 5, 7] as $i) {
            $this->print('%s + %s + %s = ', $value, $delay, $i);
            $v = $value + $delay + $i;
            $this->println($v);
        }
        // debug
        $this->isDebugMode() && __LOG_MESSAGE('这是debug日志信息');
        // 警告
        $this->addWarn('这是一条警告');
        // 错误
        $this->addError('这是一条错误');
        return 0;
    }
}
