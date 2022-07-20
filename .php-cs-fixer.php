<?php

/*
 * This file is part of the think-command package.
 *
 * @link   https://github.com/chinayin/think-command
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (!file_exists(__DIR__ . '/src')) {
    exit(0);
}
$fileHeaderComment = <<<EOF
This file is part of the think-command package.

@link   https://github.com/chinayin/think-command

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF;

return (new PhpCsFixer\Config())
    ->setUsingCache(true)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP71Migration' => true,
        '@PHPUnit84Migration:risky' => true,
        'header_comment' => ['header' => $fileHeaderComment],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->ignoreVCSIgnored(true)
            ->files()
            ->name('*.php')
            ->exclude('vendor')
            ->exclude('tests')
            ->in(__DIR__)
    );
