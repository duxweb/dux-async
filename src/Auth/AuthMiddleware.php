<?php

declare(strict_types=1);

namespace Dux\Auth;

use Core\Utils\Attribute;
use Firebase\JWT\JWT;
use JimTools\JwtAuth\Decoder\FirebaseDecoder;
use JimTools\JwtAuth\Handlers\AfterHandlerInterface;
use JimTools\JwtAuth\Handlers\BeforeHandlerInterface;
use JimTools\JwtAuth\Options;
use JimTools\JwtAuth\Secret;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class AuthMiddleware
{
    public function __construct(
        public string $app,
    ) {}

    public function __invoke(Request $request, RequestHandler $handler): Response
    {

        $auth = Attribute::getRequestParams($request, "auth");

        if ($auth !== null) {
            if (!$auth) {
                return $handler->handle($request);
            }
        }

        $secret = \Core\App::config("use")->get("app.secret");
        $app = $this->app;
        $jwt = new \JimTools\JwtAuth\Middleware\JwtAuthentication(
            new Options(
                before: new class($app) implements BeforeHandlerInterface {
                    public function __construct(public string $app) {}
                    public function __invoke(Request $request, array $arguments): Request
                    {
                        $token = $arguments["decoded"];
                        return $request->withAttribute('auth', $token)->withAttribute('app', $this->app);
                    }
                },
                after: new class($secret, $app) implements AfterHandlerInterface {
                    public function __construct(public string $secret, public string $app) {}
                    public function __invoke(Response $response, array $arguments): Response
                    {
                        $token = $arguments["decoded"];
                        if ($this->app != $token["sub"]) {
                            throw new \Core\Handlers\ExceptionBusiness("Authorization app error", 401);
                        }
                        $expire =  $token["exp"] - $token["iat"];
                        $renewalTime = $token["iat"] + round($expire / 3);
                        $time = time();
                        if ($renewalTime <= $time) {
                            $token["exp"] = $time + $expire;
                            $auth = JWT::encode($token, $this->secret, 'HS256');
                            return $response->withHeader("Authorization", "Bearer $auth");
                        }
                        return $response;
                    }
                },
            ),
            new FirebaseDecoder(new Secret($secret, 'HS256')),
        );
        return $jwt->process($request, $handler);
    }
}
