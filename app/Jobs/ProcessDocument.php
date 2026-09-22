<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Ocr\OcrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $documentId
    ) {
    }

    public function handle(OcrService $ocrService): void
    {
        $document = Document::findOrFail($this->documentId);

        $document->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            if (!Storage::exists($document->file_path)) {
                throw new \RuntimeException(
                    'Arquivo do documento não encontrado.'
                );
            }

            $absolutePath = Storage::path($document->file_path);

            $text = $ocrService->extractText($absolutePath);

            $document->update([
                'status' => 'processed',
                'extracted_text' => $text,
            ]);
        } catch (Throwable $exception) {
            $document->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
