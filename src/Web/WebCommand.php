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
use Swoole\Runtime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Swoole\Coroutine\run;

class WebCommand extends Command
{

    protected function configure(): void
    {
        $this->setName("web:start")->setDescription('Web service start');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {

        Runtime::enableCoroutine();
        ContextManage::init();

        run(function () {
            $host = App::$host;
            $port = App::$port;

            $data = [
                'Version' => App::$version,
                'PHP' => phpversion(),
                'Swoole' => SWOOLE_VERSION,
                'Debug' => App::$debug ? 'true' : 'false',
                'PID' => getmypid(),
            ];
            $extra = [
                'Web server' => "http://{$host}:{$port}",
            ];
            App::banner($data, $extra);

            $http = new Server($host, $port, false);

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

                if ($this->staticFile($request, $response) !== null) {
                    return $response;
                }

                (new SwooleResponseEmitter())->emit(
                    App::web()->handle($factory->create($request)),
                    $response
                );

                return $response;
            });

            $http->start();

            $http->shutdown();
        });

        return Command::SUCCESS;
    }

    public function staticFile(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        $path = $request->server['request_uri'];
        $path = urldecode($path);
        $filename = public_path(trim($path, '/'));
        $realFile = realpath($filename);

        $parts = pathinfo($path);
        $fileInfo = pathinfo($parts['basename']);
        $extension = key_exists('extension', $fileInfo) ? $fileInfo['extension'] : '';

        if (
            !$realFile ||
            !is_file($realFile) ||
            !str_starts_with($realFile, public_path()) ||
            str_starts_with($parts['basename'], '.') ||
            $extension == 'php'
        ) {
            return;
        }

        $fileSize = filesize($realFile);
        $extension = pathinfo($realFile, PATHINFO_EXTENSION);

        $type = new \FileEye\MimeMap\Extension($extension);
        $contentType = $type->getDefaultType();
        $response->header('Cache-Control', 'public, max-age=86400');
        $response->header('Content-Type', $contentType);


        $acceptEncoding = isset($request->header['accept-encoding']) ? $request->header['accept-encoding'] : '';
        $supportsGzip = str_contains($acceptEncoding, 'gzip');
        $supportsDeflate = str_contains($acceptEncoding, 'deflate');

        // 小文件发送
        if ($fileSize < 10 * 1024 * 1024 && ($supportsGzip || $supportsDeflate)) {
            $encoding = $supportsGzip ? 'gzip' : 'deflate';
            $response->header('Content-Encoding', $encoding);
            $response->header('Connection', 'keep-alive');
            $etag = md5_file($realFile);
            $response->header('ETag', $etag);

            $ifNoneMatch = isset($request->header['if-none-match']) ? $request->header['if-none-match'] : '';
            if ($ifNoneMatch === $etag) {
                $response->status(304);
                return $response;
            }

            $content = file_get_contents($realFile);
            if ($content !== false) {
                $compressedContent = $encoding === 'gzip' ? gzencode($content, 9) : gzdeflate($content, 9);
                if ($compressedContent !== false) {
                    $response->write($compressedContent);
                }
            }
            return $response;
        }

        // 大文件发送
        $response->header('Content-Length', (string)$fileSize);
        $response->sendfile($realFile);
        return $response;
    }
}
