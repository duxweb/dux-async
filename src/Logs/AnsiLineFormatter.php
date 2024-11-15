<?php

namespace Core\Logs;

use Core\App;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use function Termwind\render;

class AnsiLineFormatter extends LineFormatter
{
    private const LEVEL_MAP = [
        'DEBUG' => 'DEBUG',
        'INFO' => 'INFO',
        'NOTICE' => 'NOTICE',
        'WARNING' => 'WARN',
        'ERROR' => 'ERRO',
        'CRITICAL' => 'CRIT',
        'ALERT' => 'ALERT',
        'EMERGENCY' => 'EMERG',
    ];

    public function __construct()
    {
        parent::__construct(null, null, true);
    }

    public function format(LogRecord $record): string
    {
        $levelStyle = match ($record->level->value) {
            Level::Debug->value => 'text-blue-500',
            Level::Info->value => 'text-cyan-500',
            Level::Notice->value => 'text-success-500',
            Level::Warning->value => 'text-yellow-500',
            Level::Error->value => 'text-red-500',
            Level::Critical->value => 'text-red-600',
            Level::Alert->value => 'text-red-600',
            Level::Emergency->value => 'text-red-600',
        };

        $time = $record->datetime->format('Y-m-d H:i:s');
        $levelName = strtoupper(self::LEVEL_MAP[$record->level->name] ?? $record->level->name);
        $context = $record->context;

        $caller = 'unknown:0';
        if (isset($record->extra['file'])) {
            $caller = $this->getCallerInfo($record->extra['file']);
        }

        $context = $this->formatContext($context);

        render(<<<HTML
            <div class="flex">
                <span class="text-white">{$time}</span>
                <span class="ml-1 {$levelStyle} font-bold">{$levelName}</span>
                <span class="ml-1 text-gray-500">&lt;{$caller}&gt;</span>
                <span class="ml-1 text-gray-500">{$record->channel}:</span>
                <span class="ml-1">{$record->message}</span>
                {$context}
            </div>
        HTML);

        return '';
    }


    private function getCallerInfo(array $file): string
    {
        return basename($file['file']) . ':' . ($file['line'] ?? 0);
    }

    private function formatContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }

        $normalParts = [];
        $trace = null;
        $file = null;

        // 处理信息
        foreach ($context as $key => $value) {
            // 保存 trace 和 file 信息，最后处理
            if ($key === 'trace') {
                $trace = $value;
                continue;
            }
            if ($key === 'file') {
                $file = $value;
                continue;
            }

            if ($value instanceof \Throwable) {
                $value = $value->getMessage();
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $normalParts[] = sprintf(
                '<span class="text-gray-500">%s</span>=<span>%s</span>',
                $key,
                $value
            );
        }

        // 组合输出
        $output = [];

        // 普通信息添加到第一行
        if (!empty($normalParts)) {
            $output[] = '<span class="ml-2">' . implode(' ', $normalParts) . '</span>';
        }

        // 如果有堆栈信息，先添加文件信息，再添加堆栈
        if ($trace) {
            if ($file) {
                $output[] = sprintf(
                    '<div><span class="text-cyan-500">in %s(%d)</span></div>',
                    $file['file'],
                    $file['line'] ?? 0
                );
            }
            $output[] = $this->formatTrace($trace);
        }

        return implode("\n", $output);
    }

    private function formatTrace(array $trace): string
    {
        if (empty($trace)) {
            return '';
        }

        $lines = ['<div>'];

        foreach ($trace as $i => $t) {
            if (!isset($t['file'])) continue;

            $file = $t['file'];
            $line = $t['line'] ?? 0;
            $class = $t['class'] ?? '';
            $type = $t['type'] ?? '';
            $function = $t['function'] ?? '';

            $lines[] = sprintf(
                '<div>
                    <span class="text-gray-500 mr-1">#%d</span>
                    <span class="text-cyan-500 mr-1">%s(%d):</span>
                    <span class="text-yellow-500 mr-1">%s%s%s()</span>
                </div>',
                $i,
                $file,
                $line,
                $class,
                $type,
                $function
            );
        }

        $lines[] = '</div>';
        return implode("\n", $lines);
    }
}
