<?php
declare(strict_types=1);


namespace Core\Web;

use Chubbyphp\SwooleRequestHandler\PsrRequestFactory;
use Chubbyphp\SwooleRequestHandler\SwooleResponseEmitter;
use Core\App;
use Core\Coroutine\ContextManage;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UploadedFileFactory;
use Swoole\Coroutine\Http\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Swoole\Coroutine\run;

class WebCommand extends Command {

    protected function configure(): void
    {
        $this->setName("web")->setDescription('Web service start')
            ->addArgument('workermanArgs', InputArgument::IS_ARRAY, 'Arguments for Workerman (start, stop, reload)');
    }

    public function execute(InputInterface $input, OutputInterface $output): int {

        run(function () {

            ContextManage::init();

            $http = new Server('0.0.0.0', 8080, false);

            $http->set([
                'debug_mode' => true,
                'enable_preemptive_scheduler' => true,
                'hook_flags' => SWOOLE_HOOK_ALL
            ]);

            $factory = new PsrRequestFactory(
                new ServerRequestFactory(),
                new StreamFactory(),
                new UploadedFileFactory()
            );

            $http->handle('/', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) use ($factory) {
                (new SwooleResponseEmitter())->emit(
                    App::web()->handle($factory->create($request)),
                    $response
                );
                return $response;
            });





            $http->start();
        });

        return Command::SUCCESS;
    }
}