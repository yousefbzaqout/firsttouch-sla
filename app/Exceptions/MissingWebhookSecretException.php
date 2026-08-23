<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class MissingWebhookSecretException extends RuntimeException
{
    public function __construct(string $source)
    {
        parent::__construct("Webhook secret is missing for source [{$source}].");
    }
}
