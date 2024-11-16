<?php

declare(strict_types=1);


namespace Core\Queue;

use Core\App;
use Core\Coroutine\ContextManage;
use Core\Utils\Fmt;
use Swoole\Runtime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Swoole\Coroutine\run;

class QueueCommand extends Command
{

    protected function configure(): void
    {
        $this->setName("queue:start")->setDescription('Queue service start');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        Runtime::enableCoroutine();
        ContextManage::init();
        run(function () {
            $queue = App::queue();
            $data = [
                'Version' => App::$version,
                'PHP' => phpversion(),
                'Swoole' => SWOOLE_VERSION,
                'PID' => getmypid(),
                'Worker' => $queue->getWorkerNumber(),
            ];
            App::banner($data);

            $queue->register('ping', function () {
                App::log("queue")->info('ping');
            });

            $queue->send('ping', 'default', [], [
                'delay' => 5,
            ]);
            $queue->start();
        });

        return Command::SUCCESS;
    }
}
