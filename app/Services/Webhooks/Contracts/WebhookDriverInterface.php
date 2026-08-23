<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Contracts;

use App\Adapters\Webhooks\Contracts\WebhookAdapterInterface;

/**
 * Strategy contract for inbound lead-form webhook drivers.
 * Extends the adapter interface so drivers plug into the existing ingestion pipeline.
 */
interface WebhookDriverInterface extends WebhookAdapterInterface {}
