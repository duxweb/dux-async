<?php

namespace Core\Middleware;

use FileEye\MimeMap\Extension;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;


final class StaticMiddleware implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {

        $response = $handler->handle($request);
        if ($request->getMethod() !== 'GET') {
            return $response;
        }

        $path = $request->getUri()->getPath();
        $path = urldecode($path);
        $filename = public_path($path);
        $realFile = realpath($filename);

        $parts = pathinfo($path);
        $fileInfo = pathinfo($parts['basename']);

        $extension = key_exists('extension', $fileInfo) ? $fileInfo['extension'] : '';

        if ($realFile &&
            is_file($realFile) &&
            str_starts_with($realFile, public_path()) &&
            !str_starts_with($parts['basename'], '.') &&
            $extension != 'php'
        ) {

            $res = self::sendFile($realFile, new Response());

            return $res;
        }
        return $response;
    }

    public function sendFile($file, ResponseInterface $response): \Psr\Http\Message\MessageInterface|ResponseInterface
    {
        $fileSize = filesize($file);
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        // 获取默认的 MIME 类型
        $type = new Extension($extension);
        $contentType = $type->getDefaultType();


        // 设置响应头
        $response = $response->withHeader('Content-Type', $contentType);

        if ($fileSize < 1024 * 1024 * 2) {
            $response = $response->withHeader('Content-Length', $fileSize);
            $response->getBody()->write(file_get_contents($file));
            return $response;
        }

        // 大文件分块发送
        $response = $response->withHeader('Transfer-Encoding', 'chunked');

        $fileHandler = fopen($file, 'r');
        $doWrite = function () use ($fileHandler, $response) {
            while (!feof($fileHandler)) {
                $buffer = fread($fileHandler, 64 * 8 * 1024);
                if ($buffer === false || $buffer === '') {
                    return;
                }
                $response->getBody()->write($buffer);
            }
        };

        $doWrite();

        fclose($fileHandler);

        return $response;
    }

}