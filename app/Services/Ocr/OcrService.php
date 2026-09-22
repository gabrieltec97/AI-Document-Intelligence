<?php

namespace App\Services\Ocr;

interface OcrService
{
    public function extractText(string $filePath): string;
}
