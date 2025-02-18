<?php

namespace Core\Resources\Action;

use Core\App;
use Core\Validator\Data;
use Core\Validator\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

trait Create
{

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->method = 'create';
        $this->init($request, $response, $args);
        $this->event->run('init', $request, $response, $args);

        $requestData = $request->getParsedBody() ?: [];

        $validator = $this->validator($requestData, $request, $args);
        $validatorEvent = $this->event->get('validator', $requestData, $request, $args);
        $data = Validator::parser([...$requestData, ...$args], [...$validator, ...$validatorEvent]);

        App::db()->getConnection()->beginTransaction();

        $format = $this->format($data, $request, $args);
        $formatEvent = $this->event->get('format', $data, $request, $args);
        $modelData = $this->formatData([...$format, ...$formatEvent], $data);

        $model = new $this->model;
        foreach ($modelData as $key => $vo) {
            $model->$key = $vo;
        }

        $this->createBefore($data, $model);
        $this->event->run('createBefore', $data, $model);

        $model->save();

        $this->createAfter($data, $model);
        $this->event->run('createAfter', $data, $model);

        App::db()->getConnection()->commit();

        return send($response, $this->translation($request, 'create'));
    }

    public function createBefore(Data $data, mixed $info): void
    {
    }

    public function createAfter(Data $data, mixed $info): void
    {
    }

}