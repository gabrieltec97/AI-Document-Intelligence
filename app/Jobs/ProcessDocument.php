<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Ocr\OcrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
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
        $document = DB::transaction(function () {
            $document = Document::query()
                ->lockForUpdate()
                ->find($this->documentId);

            if (!$document) {
                return null;
            }

            if (in_array($document->status, ['processing', 'processed'])) {
                return null;
            }

            $document->update([
                'status' => 'processing',
                'error_message' => null,
            ]);

            return $document;
        });

        if (!$document) {
            return;
        }

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
