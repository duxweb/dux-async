<?php

namespace Core\Middleware;

use Core\Utils\Fmt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Slim\Routing\RouteContext;
use Swoole\Coroutine;

final class RequestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 生成请求ID
        $requestId = $this->generateRequestId();

        // 记录请求开始时间
        $startTime = microtime(true);

        // 添加请求ID到请求头
        $request = $request->withAttribute('request_id', $requestId);

        // 获取请求信息
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '-';

        try {
            // 处理请求
            $response = $handler->handle($request);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logger->info("", [
                'request_id' => $requestId,
                'method' => $method,
                'uri' => $uri,
                'ip' => $ip,
                'status' => $response->getStatusCode(),
                'duration' => round($duration, 2) . 'ms',
            ]);

            return $response->withHeader('X-Request-ID', $requestId);
        } catch (\Throwable $e) {

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger?->error("Failed", [
                'file' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ],
                'request_id' => $requestId,
                'method' => $method,
                'duration' => round($duration, 2) . 'ms',
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }


    private function generateRequestId(): string
    {
        return sprintf(
            '%x-%x-%s',
            time(),
            floor(microtime(true) * 1000),
            bin2hex(random_bytes(4))
        );
    }
}
