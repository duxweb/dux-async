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

            $res = self::sendFile($realFile, $request, new Response());

            return $res;
        }
        return $response;
    }

    public function sendFile($file,ServerRequestInterface $request,  ResponseInterface $response): \Psr\Http\Message\MessageInterface|ResponseInterface
    {
        $fileSize = filesize($file);
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        $type = new Extension($extension);
        $contentType = $type->getDefaultType();

        $acceptEncoding = $request->getHeaderLine('Accept-Encoding');
        $supportsGzip = str_contains($acceptEncoding, 'gzip');
        $supportsDeflate = str_contains($acceptEncoding, 'deflate');

        if ($supportsGzip || $supportsDeflate) {
            $response = $response->withHeader('Content-Encoding', $supportsGzip ? 'gzip' : 'deflate')
                ->withHeader('Content-Type', $contentType);

            $fileHandler = fopen($file, 'r');
            $stream = '';

            while (!feof($fileHandler)) {
                $chunk = fread($fileHandler, 64 * 1024); // 分块读取
                $stream .= $supportsGzip ? gzencode($chunk) : gzdeflate($chunk); // 分块压缩
            }

            fclose($fileHandler);
            $response->getBody()->write($stream);
            return $response;
        }

        // 未压缩的大文件，分块发送
        if ($fileSize > 2 * 1024 * 1024) {
            $response = $response->withHeader('Content-Type', $contentType)
                ->withHeader('Transfer-Encoding', 'chunked');


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

        // 小文件直接发送
        $response = $response->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', $fileSize);
        $response->getBody()->write(file_get_contents($file));
        return $response;
    }

}