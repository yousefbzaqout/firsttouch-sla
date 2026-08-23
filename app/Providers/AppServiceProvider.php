<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Lead;
use App\Models\TenantSetting;
use App\Models\User;
use App\Observers\LeadObserver;
use App\Observers\TenantSettingObserver;
use App\Observers\UserObserver;
use App\Services\Ai\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\OpenAiEmbeddingService;
use App\Services\Ai\OpenRouterLlmService;
use App\Services\Knowledge\Contracts\KnowledgeTextExtractorInterface;
use App\Services\Knowledge\Extractors\PlainTextKnowledgeExtractor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EmbeddingServiceInterface::class, fn (): OpenAiEmbeddingService => new OpenAiEmbeddingService(
            apiKey: (string) config('services.openai.api_key', ''),
            openRouterApiKey: (string) config('services.openrouter.api_key', ''),
        ));

        $this->app->bind(LlmProviderInterface::class, fn (): OpenRouterLlmService => new OpenRouterLlmService(
            (string) config('services.openrouter.api_key', ''),
        ));

        $this->app->bind(KnowledgeTextExtractorInterface::class, PlainTextKnowledgeExtractor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TenantSetting::observe(TenantSettingObserver::class);
        User::observe(UserObserver::class);
        Lead::observe(LeadObserver::class);
    }
}
