<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Chunking\ChunkingService;
use App\Services\Ocr\OcrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Throwable;
use Illuminate\Queue\Attributes\FailOnTimeout;

#[FailOnTimeout]
class ProcessDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $documentId
    ) {
    }

    public function handle(
        OcrService $ocrService,
        ChunkingService $chunkingService
    ): void {
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

            // Extrai o texto do arquivo.
            $text = $ocrService->extractText($absolutePath);

            // Divide o texto em blocos para futura busca semântica.
            $chunks = $chunkingService->split($text);

            // Salva os blocos e conclui o documento atomicamente.
            DB::transaction(function () use ($text, $chunks) {
                $document = Document::query()
                    ->lockForUpdate()
                    ->find($this->documentId);

                if (!$document || $document->status !== 'processing') {
                    return;
                }

                // Remove blocos anteriores em caso de reprocessamento.
                $document->chunks()->delete();

                $chunkRecords = [];

                foreach ($chunks as $index => $content) {
                    $chunkRecords[] = [
                        'chunk_index' => $index,
                        'content' => $content,
                        'character_count' => mb_strlen($content, 'UTF-8'),
                    ];
                }

                if ($chunkRecords !== []) {
                    $document->chunks()->createMany($chunkRecords);
                }

                $document->update([
                    'status' => 'processed',
                    'extracted_text' => $text,
                    'error_message' => null,
                ]);
            });
        } catch (Throwable $exception) {
            $document->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception) {
            $document = Document::query()
                ->lockForUpdate()
                ->find($this->documentId);

            if (!$document || $document->status === 'processed') {
                return;
            }

            $document->update([
                'status' => 'failed',
                'error_message' => $exception?->getMessage()
                    ?? 'O processamento do documento falhou definitivamente.',
            ]);
        });
    }
}
