<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Event;

final class Broadcast
{
    public static function assertBroadcasted(string $event, ?callable $callback = null): void
    {
        Event::assertDispatched($event, $callback);
    }
}
