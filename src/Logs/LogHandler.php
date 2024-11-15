<?php
declare(strict_types=1);

namespace Core\Logs;

use Core\App;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

class LogHandler {

    public static function init(string $name, Level $level): Logger {

        $fileHandle = new RotatingFileHandler(App::$dataPath . '/logs/' . $name . '.log', 15, $level, true, 0777);
        $streamHandler = new StreamHandler('php://stdout', $level);
        $streamHandler->setFormatter(new AnsiLineFormatter());
        $logger = new Logger($name);
        $logger->pushHandler($fileHandle);
        $logger->pushHandler($streamHandler);
        $logger->pushProcessor(function (LogRecord $record) {
            if (isset($record->context['file'])) {
                return $record;
            }
            //$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            //$record->context['file'] = $trace[2];
            return $record;
        });
        return $logger;
    }
    
}
