<?php

declare(strict_types=1);

namespace Core\Web;

use Chubbyphp\SwooleRequestHandler\PsrRequestFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Swoole\Http\Request as SwooleRequest;

class PsrRequestFactory implements PsrRequestFactoryInterface
{
  public function __construct(
    private ServerRequestFactoryInterface $serverRequestFactory,
    private StreamFactoryInterface $streamFactory,
    private UploadedFileFactoryInterface $uploadedFileFactory
  ) {}

  public function create(SwooleRequest $swooleRequest): ServerRequestInterface
  {
    $server = array_change_key_case($swooleRequest->server ?? [], CASE_UPPER);

    list($scheme, $protocolVersion) = explode('/', $server['SERVER_PROTOCOL']);
    $scheme = strtolower($scheme);
    $host = $server["SERVER_HOST"] ?? '0.0.0.0';
    $port = $server["SERVER_PORT"] ?? '';
    $port = in_array($port, [80, 443]) ? '' : ":{$port}";
    $requestUri = $server['REQUEST_URI'] ?? '';
    $queryString = $server['QUERY_STRING'] ?? '';
    $uri = $scheme . '://' . $host . $port . $requestUri . ($queryString ? "?{$queryString}" : '');

    $request = $this->serverRequestFactory->createServerRequest(
      $server['REQUEST_METHOD'] ?? 'GET',
      $uri,
      $server
    );

    foreach ($swooleRequest->header ?? [] as $name => $value) {
      $request = $request->withHeader($name, $value);
    }

    $request = $request->withCookieParams($swooleRequest->cookie ?? []);
    $request = $request->withQueryParams($swooleRequest->get ?? []);
    $request = $request->withParsedBody($swooleRequest->post ?? []);
    $request = $request->withUploadedFiles($this->uploadedFiles($swooleRequest->files ?? []));

    $request->getBody()->write($swooleRequest->rawContent());

    return $request;
  }

  /**
   * @param array<string, array<string, int|string>> $files
   *
   * @return array<string, UploadedFileInterface>
   */
  private function uploadedFiles(array $files): array
  {
    $uploadedFiles = [];
    foreach ($files as $key => $file) {
      $uploadedFiles[$key] = isset($file['tmp_name']) ? $this->createUploadedFile($file) : $this->uploadedFiles($file);
    }

    return $uploadedFiles;
  }

  /**
   * @param array<string, int|string> $file
   */
  private function createUploadedFile(array $file): UploadedFileInterface
  {
    try {
      $stream = $this->streamFactory->createStreamFromFile($file['tmp_name']);
    } catch (\RuntimeException) {
      $stream = $this->streamFactory->createStream();
    }

    return $this->uploadedFileFactory->createUploadedFile(
      $stream,
      $file['size'],
      $file['error'],
      $file['name'],
      $file['type']
    );
  }
}
