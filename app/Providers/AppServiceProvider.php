<?php

namespace App\Providers;

use App\Services\Embeddings\EmbeddingService;
use App\Services\Embeddings\OllamaEmbeddingService;
use App\Services\Ocr\OcrService;
use App\Services\Ocr\TesseractOcrService;
use Illuminate\Support\ServiceProvider;
use App\Services\Generation\GenerationService;
use App\Services\Generation\OllamaGenerationService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OcrService::class,
            TesseractOcrService::class
        );

        $this->app->bind(
            EmbeddingService::class,
            OllamaEmbeddingService::class
        );

        $this->app->bind(
            GenerationService::class,
            OllamaGenerationService::class
        );
    }

    public function boot(): void
    {
        //
    }
}
