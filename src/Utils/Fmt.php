<?php
declare(strict_types=1);

namespace Core\Utils;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class Fmt
{
    private static ?OutputInterface $output = null;
    private static ?VarCloner $cloner = null;
    private static ?CliDumper $dumper = null;

    /**
     * 初始化输出实例
     */
    public static function init(): void
    {
        if (self::$output === null) {
            self::$output = new ConsoleOutput(
                OutputInterface::VERBOSITY_NORMAL,
                true // 启用格式化
            );
        }

        if (self::$cloner === null) {
            self::$cloner = new VarCloner();
        }

        if (self::$dumper === null) {
            self::$dumper = new CliDumper();
        }
    }

    /**
     * 使用默认格式打印输出到标准输出
     * 当操作数都不是字符串时，会在它们之间添加空格
     * 对应 Go 的 fmt.Print()
     * 
     * @param mixed $var 要打印的变量
     */
    public static function Print(mixed $var): void
    {
        $cloned = self::$cloner->cloneVar($var);
        self::$output->write($cloned);
    }

    /**
     * 使用默认格式打印输出到标准输出，并在末尾添加换行符
     * 
     * @param mixed $var 要打印的变量
     */
    public static function Println(mixed $var): void
    {
        $cloned = self::$cloner->cloneVar($var);
        self::$dumper->dump($cloned);
    }

    /**
     * 根据格式说明符进行格式化并打印到标准输出
     * 
     * @param string $format 格式字符串
     * @param mixed ...$args 要格式化的参数
     */
    public static function Printf(string $format, ...$args): void
    {
        self::$output->write(sprintf($format, ...$args));
    }

    /**
     * 根据格式说明符进行格式化并返回结果字符串
     * 
     * @param string $format 格式字符串
     * @param mixed ...$args 要格式化的参数
     * @return string 格式化后的字符串
     */
    public static function Sprintf(string $format, ...$args): string
    {
        return sprintf($format, ...$args);
    }

    /**
     * 根据格式说明符格式化错误信息并输出
     * 
     * @param string $format 格式字符串
     * @param mixed ...$args 要格式化的参数
     */
    public static function Errorf(string $format, ...$args): void
    {
        $message = sprintf($format, ...$args);
        self::dump($message, '', 'error');
    }

    /**
     * 打印调试信息
     *
     * @param mixed $var 要打印的变量
     * @param string $label 标签（可选）
     * @param string $style 样式（info|comment|error|question|success）
     */
    public static function dump(mixed $var, string $label = '', string $style = 'info'): void
    {

        // 设置样式映射
        $styles = [
            'info' => '<fg=blue>',
            'comment' => '<fg=yellow>',
            'error' => '<fg=red>',
            'question' => '<fg=magenta>',
            'success' => '<fg=green>'
        ];

        $style = $styles[$style] ?? $styles['info'];
        
        // 打印标签
        if ($label !== '') {
            self::$output->writeln($style . str_repeat('-', 20));
            self::$output->writeln($style . $label . '</>' );
            self::$output->writeln($style . str_repeat('-', 20));
        }

        // 使用 VarDumper 处理变量
        $cloned = self::$cloner->cloneVar($var);
        self::$dumper->dump($cloned);
        
        // 打印分隔线
        if ($label !== '') {
            self::$output->writeln($style . str_repeat('-', 20) . '</>' . PHP_EOL);
        }
    }

}