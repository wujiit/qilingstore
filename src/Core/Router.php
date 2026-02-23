<?php

declare(strict_types=1);

namespace Qiling\Core;

use Qiling\Support\Response;

final class Router
{
    /** @var array<int, array{method:string,path:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => rtrim($path, '/') ?: '/',
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uriPath): void
    {
        $method = strtoupper($method);
        $uriPath = rtrim($uriPath, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if ($route['path'] === $uriPath) {
                ($route['handler'])();
                return;
            }
        }

        Response::json(['message' => 'Route not found'], 404);
    }
}
