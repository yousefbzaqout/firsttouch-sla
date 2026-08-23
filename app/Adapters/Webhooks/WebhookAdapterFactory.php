<?php

declare(strict_types=1);

namespace App\Adapters\Webhooks;

use App\Adapters\Webhooks\Contracts\WebhookAdapterInterface;
use App\Enums\LeadSource;
use App\Services\Webhooks\Drivers\GoogleAdsWebhookDriver;
use App\Services\Webhooks\Drivers\SnapchatAdsWebhookDriver;
use App\Services\Webhooks\Drivers\UniversalWebhookDriver;
use App\Services\Webhooks\Drivers\WebsiteFormWebhookDriver;
use InvalidArgumentException;

class WebhookAdapterFactory
{
    public function resolve(LeadSource $source): WebhookAdapterInterface
    {
        return match ($source) {
            LeadSource::Meta => new MetaWebhookAdapter,
            LeadSource::TikTok => new TikTokWebhookAdapter,
            LeadSource::Google => new GoogleAdsWebhookDriver,
            LeadSource::Snapchat => new SnapchatAdsWebhookDriver,
            LeadSource::Universal => new UniversalWebhookDriver,
            LeadSource::Website => new WebsiteFormWebhookDriver,
            default => throw new InvalidArgumentException("No webhook adapter for source: {$source->value}"),
        };
    }
}
