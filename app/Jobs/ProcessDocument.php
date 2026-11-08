<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Chunking\ChunkingService;
use App\Services\Embeddings\EmbeddingService;
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

    private const EMBEDDING_BATCH_SIZE = 16;

    public function __construct(
        public int $documentId
    ) {
    }

    public function handle(
        OcrService $ocrService,
        ChunkingService $chunkingService,
        EmbeddingService $embeddingService
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

            // Divide o texto em blocos e identifica seus metadados.
            $chunkData = $chunkingService->splitWithMetadata($text);

            // Extrai somente o conteúdo para gerar os embeddings.
            $chunks = array_column($chunkData, 'content');

            // Gera embeddings em lotes, fora da transação do banco.
            $embeddings = [];

            foreach (array_chunk($chunks, self::EMBEDDING_BATCH_SIZE) as $batch) {
                $embeddings = array_merge(
                    $embeddings,
                    $embeddingService->embed($batch)
                );
            }

            $embeddingModel = config('services.ollama.embedding_model');

            // Salva os blocos, metadados e embeddings atomicamente.
            DB::transaction(function () use (
                $text,
                $chunkData,
                $embeddings,
                $embeddingModel
            ) {
                $document = Document::query()
                    ->lockForUpdate()
                    ->find($this->documentId);

                if (!$document || $document->status !== 'processing') {
                    return;
                }

                // Remove blocos anteriores em caso de reprocessamento.
                $document->chunks()->delete();

                $chunkRecords = [];

                foreach ($chunkData as $index => $chunk) {
                    $content = $chunk['content'];

                    $chunkRecords[] = [
                        'chunk_index' => $index,
                        'content' => $content,
                        'section' => $chunk['section'],
                        'company' => $chunk['company'],
                        'position' => $chunk['position'],
                        'character_count' => mb_strlen($content, 'UTF-8'),
                        'embedding' => $embeddings[$index],
                        'embedding_model' => $embeddingModel,
                        'embedded_at' => now(),
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
