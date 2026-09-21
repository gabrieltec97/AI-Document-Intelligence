<?php

namespace App\Jobs;

use App\Models\Document;
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

    /**
     * Execute the job.
     */
    public function handle(): void
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

            // OCR será implementado aqui.
            // Por enquanto, apenas simulei o processamento.

            $document->update([
                'status' => 'processed',
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
