<?php

namespace App\Providers;

use App\Services\Ocr\OcrService;
use App\Services\Ocr\TesseractOcrService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OcrService::class,
            TesseractOcrService::class
        );
    }

    public function boot(): void
    {
        //
    }
}
