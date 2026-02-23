<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Support\Response;

final class HealthController
{
    public static function index(): void
    {
        Response::json([
            'service' => 'qiling-medspa-system',
            'status' => 'ok',
            'time' => gmdate('c'),
        ]);
    }
}
