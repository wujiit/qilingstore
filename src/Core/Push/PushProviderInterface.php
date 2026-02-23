<?php

declare(strict_types=1);

namespace Qiling\Core\Push;

interface PushProviderInterface
{
    /**
     * @param array<string, mixed> $channel
     * @return array<string, mixed>
     */
    public function send(array $channel, string $message, array $args = []): array;
}
