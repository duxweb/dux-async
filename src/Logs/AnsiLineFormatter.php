<?php

namespace Core\Logs;

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
        
        $caller = $this->getCallerInfo();
        $context = $this->formatContext(array_merge($record->context, $record->extra));

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

    private function getCallerInfo(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        
        // 从后向前遍历，找到第一个有效的调用
        for ($i = count($trace) - 1; $i >= 0; $i--) {
            $t = $trace[$i];
            
            // 跳过闭包调用
            if (isset($t['function']) && str_contains($t['function'], '{closure}')) {
                continue;
            }
            
            // 如果有文件信息，直接返回
            if (isset($t['file'])) {
                return basename(dirname($t['file'])) . '/' . basename($t['file']) . ':' . ($t['line'] ?? 0);
            }
        }

        return 'unknown:0';
    }

    private function formatContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }

        $parts = [];
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $value = $value->getMessage();
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = sprintf(
                '<span class="text-gray-500">%s</span>=<span>%s</span>', 
                $key, 
                $value
            );
        }

        return '<span class="ml-2">' . implode(' ', $parts) . '</span>';
    }
}