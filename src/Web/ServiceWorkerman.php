<?php
declare(strict_types=1);


namespace Core\Web;

use Comet\Comet;
use Comet\Factory\CometPsr17Factory;
use Comet\Request;
use Comet\Response;
use Core\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Factory\Psr17\Psr17FactoryProvider;
use Workerman\Connection\TcpConnection as WorkermanTcpConnection;
use Workerman\Protocols\Http;
use Workerman\Worker;

class ServiceWorkerman  {

    private static \Slim\App|Comet $app;
    private static bool $serveStatic = false;
    private static string $staticDir;

    private static int $trunkLimitSize = 2 * 1024 * 1024;

    private static Worker $worker;


    public function __construct(public string $host, public string $port, public int $workers)
    {
        // 系统环境处理
        if (DIRECTORY_SEPARATOR === '\\') {
            if ($this->host === '0.0.0.0') {
                $this->host = '127.0.0.1';
            }
            $this->workers = 1;
        } else {
            $this->host = '0.0.0.0';
        }

        // 使用 Comet PSR-7 PSR-17
        $provider = new Psr17FactoryProvider();
        $provider::setFactories([ CometPsr17Factory::class ]);
        AppFactory::setPsr17FactoryProvider($provider);

        // 使用DI容器
        AppFactory::setContainer(App::di());

        // 创建 Slim 实例
        self::$app = AppFactory::create();
    }

    public function getWorker(): Worker
    {
        return self::$worker;
    }


    public function getApp(): \Slim\App
    {
        return self::$app;
    }

    /**
     * 设置静态目录
     * @param string $dir
     * @return void
     */
    public static function static(string $dir): void
    {
        self::$serveStatic = true;
        if ($dir[0] == '/' || strpos($dir, ':')) {
            self::$staticDir = $dir;
        } else {
            self::$staticDir = base_path($dir);
        }
    }

    public function load(): void
    {
        foreach (App::log()->getHandlers() as $handler) {
            if ($handler->getUrl()) {
                Worker::$stdoutFile = $handler->getUrl();
                break;
            }
        }

        $worker = new Worker("http://$this->host:$this->port");
        $worker->name = 'web';
        $worker->count = $this->workers;

        Http::requestClass(Request::class);

        $worker->onMessage = static function (WorkermanTcpConnection $connection, Request $request) {
            try {
                if (self::$serveStatic && $request->getMethod() === 'GET') {

                    $path = $request->getUri()->getPath();
                    $path = urldecode($path);
                    $filename = self::$staticDir . '/' . $path;
                    $realFile = realpath($filename);

                    $parts = pathinfo($path);
                    $fileInfo = pathinfo($parts['basename']);
                    $extension = key_exists('extension', $fileInfo) ? $fileInfo['extension'] : '';

                    if ($realFile &&
                        is_file($realFile) &&
                        str_starts_with($realFile, realpath(self::$staticDir)) &&
                        !str_starts_with($parts['basename'], '.') &&
                        $extension != 'php'
                    ) {
                        return self::sendFile($connection, $realFile);
                    }
                }
                $response = self::handle($request);
                $connection->send($response);

            } catch (HttpNotFoundException $error) {
                $connection->send(new Response(404));
            } catch (\Throwable $error) {
                App::log()->error($error->getFile() . ':' . $error->getLine() . ' >> ' . $error->getMessage());
                $connection->send(new Response(500));
            }

            return null;
        };

        self::$worker = $worker;
    }

    private static function handle(Request $request): \Psr\Http\Message\ResponseInterface
    {
        $response = self::$app->handle($request);
        $headers = $response->getHeaders();
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'text/html; charset=utf-8';
        }
        return $response->withHeaders($headers);
    }

    public static function sendFile(WorkermanTcpConnection $connection, string $file): bool|null
    {
        $fileSize = filesize($file);
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        $type = new \FileEye\MimeMap\Extension($extension);

        $contentType = $type->getDefaultType();
        $headers  = "HTTP/1.1 200 OK\r\n";
        $headers .= "Content-Type: $contentType\r\n";
        $headers .= "Connection: keep-alive\r\n";
        $headers .= "Content-Length: $fileSize\r\n\r\n";

        if ($fileSize < self::$trunkLimitSize) {
            return $connection->send($headers . file_get_contents($file), true);
        }

        $connection->send($headers, true);
        $connection->fileHandler = fopen($file, 'r');

        $do_write = function() use ($connection)
        {
            while (empty($connection->bufferFull)) {
                $buffer = fread($connection->fileHandler, 64 * 8 * 1024);
                if($buffer === '' || $buffer === false) {
                    return;
                }
                $connection->send($buffer, true);
            }
        };
        $connection->onBufferFull = function($connection)
        {
            $connection->bufferFull = true;
        };

        $connection->onBufferDrain = function($connection) use ($do_write)
        {
            $connection->bufferFull = false;
            $do_write();
        };

        $do_write();

        return null;
    }
}