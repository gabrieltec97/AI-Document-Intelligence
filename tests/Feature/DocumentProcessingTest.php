<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\Chunking\ChunkingService;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Ocr\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DocumentProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->mock(EmbeddingService::class)
            ->shouldReceive('embed')
            ->andReturnUsing(
                fn (array $texts): array => $this->fakeEmbeddings($texts)
            );
    }

    private function fakeEmbeddings(array $texts): array
    {
        return array_fill(
            0,
            count($texts),
            array_fill(0, 1024, 0.1)
        );
    }

    private function createDocument(string $status = 'uploaded'): Document
    {
        $user = User::factory()->create();

        return Document::create([
            'user_id' => $user->id,
            'original_name' => 'teste.pdf',
            'file_path' => 'documents/teste.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => $status,
        ]);
    }

    public function test_document_is_processed_successfully(): void
    {
        $document = $this->createDocument();

        Storage::disk('local')->put(
            $document->file_path,
            'conteudo de teste'
        );

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldReceive('extractText')
            ->once()
            ->with(Storage::path($document->file_path))
            ->andReturn('Texto extraído do documento.');

        (new ProcessDocument($document->id))->handle(
            $ocr,
            app(ChunkingService::class), app(EmbeddingService::class)
        );

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
            'extracted_text' => 'Texto extraído do documento.',
            'error_message' => null,
        ]);

        $this->assertDatabaseHas('document_chunks', [
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Texto extraído do documento.',
            'character_count' => mb_strlen('Texto extraído do documento.', 'UTF-8'),
        ]);

        $this->assertSame(1, $document->chunks()->count());

        $chunk = $document->chunks()->first();

        $this->assertIsArray($chunk->embedding);
        $this->assertCount(1024, $chunk->embedding);
        $this->assertSame(
            config('services.ollama.embedding_model'),
            $chunk->embedding_model
        );
        $this->assertNotNull($chunk->embedded_at);
    }

    public function test_large_document_is_saved_in_multiple_chunks(): void
    {
        $document = $this->createDocument();

        Storage::disk('local')->put(
            $document->file_path,
            'conteudo de teste'
        );

        $text = implode(' ', array_map(
            fn (int $number) => "Palavra{$number}",
            range(1, 400)
        ));

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldReceive('extractText')
            ->once()
            ->andReturn($text);

        (new ProcessDocument($document->id))->handle(
            $ocr,
            app(ChunkingService::class), app(EmbeddingService::class)
        );

        $chunks = $document->chunks()->get();

        $this->assertGreaterThan(1, $chunks->count());

        $this->assertSame(
            range(0, $chunks->count() - 1),
            $chunks->pluck('chunk_index')->all()
        );

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(1500, $chunk->character_count);

            $this->assertSame(
                mb_strlen($chunk->content, 'UTF-8'),
                $chunk->character_count
            );
        }

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
            'extracted_text' => $text,
        ]);
    }

    public function test_embeddings_are_generated_in_batches_of_sixteen(): void
    {
        $document = $this->createDocument();

        Storage::disk('local')->put(
            $document->file_path,
            'conteudo de teste'
        );

        // Gera texto suficiente para produzir pelo menos 17 chunks.
        $text = str_repeat('Palavra de teste para gerar chunks. ', 1000);

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldReceive('extractText')
            ->once()
            ->andReturn($text);

        $embeddingService = $this->mock(EmbeddingService::class);

        $embeddingService->shouldReceive('embed')
            ->twice()
            ->withArgs(function (array $texts): bool {
                return count($texts) >= 1 && count($texts) <= 16;
            })
            ->andReturnUsing(
                fn (array $texts): array => $this->fakeEmbeddings($texts)
            );

        (new ProcessDocument($document->id))->handle(
            $ocr,
            app(ChunkingService::class),
            $embeddingService
        );

        $this->assertGreaterThanOrEqual(17, $document->chunks()->count());

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
        ]);
    }

    public function test_missing_file_marks_document_as_failed(): void
    {
        $document = $this->createDocument();

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldNotReceive('extractText');

        try {
            (new ProcessDocument($document->id))->handle($ocr, app(ChunkingService::class), app(EmbeddingService::class));

            $this->fail('Era esperada uma exceção para arquivo inexistente.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Arquivo do documento não encontrado.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'failed',
            'error_message' => 'Arquivo do documento não encontrado.',
        ]);
    }

    public function test_ocr_failure_marks_document_as_failed(): void
    {
        $document = $this->createDocument();

        Storage::disk('local')->put(
            $document->file_path,
            'conteudo de teste'
        );

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldReceive('extractText')
            ->once()
            ->andThrow(new RuntimeException('Falha simulada no OCR.'));

        try {
            (new ProcessDocument($document->id))->handle($ocr, app(ChunkingService::class), app(EmbeddingService::class));

            $this->fail('Era esperada uma exceção do OCR.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Falha simulada no OCR.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'failed',
            'error_message' => 'Falha simulada no OCR.',
        ]);
    }

    public function test_already_processed_document_with_chunks_is_not_processed_again(): void
    {
        $document = $this->createDocument('processed');

        $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Documento já indexado.',
            'character_count' => mb_strlen('Documento já indexado.', 'UTF-8'),
        ]);

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldNotReceive('extractText');

        (new ProcessDocument($document->id))->handle(
            $ocr,
            app(ChunkingService::class),
            app(EmbeddingService::class)
        );

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
        ]);

        $this->assertSame(1, $document->chunks()->count());
    }

    public function test_processed_document_without_chunks_is_reindexed_using_extracted_text(): void
    {
        $document = $this->createDocument('processed');

        $text = 'Texto extraído anteriormente e pronto para indexação.';

        $document->update([
            'extracted_text' => $text,
        ]);

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldNotReceive('extractText');

        (new ProcessDocument($document->id))->handle(
            $ocr,
            app(ChunkingService::class),
            app(EmbeddingService::class)
        );

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
            'extracted_text' => $text,
            'error_message' => null,
        ]);

        $this->assertDatabaseHas('document_chunks', [
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => $text,
        ]);

        $this->assertSame(1, $document->chunks()->count());
    }

    public function test_empty_ocr_result_marks_document_as_failed(): void
    {
        $document = $this->createDocument();

        Storage::disk('local')->put(
            $document->file_path,
            'conteudo de teste'
        );

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldReceive('extractText')
            ->once()
            ->andReturn('');

        try {
            (new ProcessDocument($document->id))->handle(
                $ocr,
                app(ChunkingService::class),
                app(EmbeddingService::class)
            );

            $this->fail('Era esperada uma exceção para texto vazio.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Não foi possível gerar blocos de conteúdo para indexação.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'failed',
        ]);

        $this->assertSame(0, $document->chunks()->count());
    }

    public function test_nonexistent_document_is_ignored(): void
    {
        $ocr = $this->mock(OcrService::class);

        $ocr->shouldNotReceive('extractText');

        (new ProcessDocument(999999))->handle($ocr, app(ChunkingService::class), app(EmbeddingService::class));

        $this->assertDatabaseMissing('documents', [
            'id' => 999999,
        ]);
    }

    public function test_document_already_processing_is_not_processed_again(): void
    {
        $document = $this->createDocument('processing');

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldNotReceive('extractText');

        (new ProcessDocument($document->id))->handle($ocr, app(ChunkingService::class), app(EmbeddingService::class));

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processing',
        ]);
    }

    public function test_failed_document_can_be_reprocessed_successfully(): void
    {
        $document = $this->createDocument('failed');

        $document->update([
            'error_message' => 'Erro anterior no processamento.',
        ]);

        // Simula chunks de uma tentativa anterior.
        $document->chunks()->createMany([
            [
                'chunk_index' => 0,
                'content' => 'Texto antigo do documento.',
                'character_count' => mb_strlen('Texto antigo do documento.', 'UTF-8'),
            ],
            [
                'chunk_index' => 1,
                'content' => 'Segundo bloco antigo.',
                'character_count' => mb_strlen('Segundo bloco antigo.', 'UTF-8'),
            ],
        ]);

        Storage::disk('local')->put(
            $document->file_path,
            'conteudo de teste'
        );

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldReceive('extractText')
            ->once()
            ->with(Storage::path($document->file_path))
            ->andReturn('Texto extraído após nova tentativa.');

        (new ProcessDocument($document->id))->handle(
            $ocr,
            app(ChunkingService::class), app(EmbeddingService::class)
        );

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
            'extracted_text' => 'Texto extraído após nova tentativa.',
            'error_message' => null,
        ]);

        $this->assertDatabaseMissing('document_chunks', [
            'document_id' => $document->id,
            'content' => 'Texto antigo do documento.',
        ]);

        $this->assertDatabaseMissing('document_chunks', [
            'document_id' => $document->id,
            'content' => 'Segundo bloco antigo.',
        ]);

        $this->assertDatabaseHas('document_chunks', [
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Texto extraído após nova tentativa.',
        ]);

        $this->assertSame(1, $document->chunks()->count());
    }

    public function test_failed_callback_marks_processing_document_as_failed(): void
    {
        $document = $this->createDocument('processing');

        $exception = new RuntimeException('Timeout no processamento.');

        (new ProcessDocument($document->id))->failed($exception);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'failed',
            'error_message' => 'Timeout no processamento.',
        ]);
    }

    public function test_failed_callback_does_not_change_processed_document(): void
    {
        $document = $this->createDocument('processed');

        $document->update([
            'extracted_text' => 'Documento processado corretamente.',
        ]);

        $exception = new RuntimeException('Erro posterior.');

        (new ProcessDocument($document->id))->failed($exception);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
            'extracted_text' => 'Documento processado corretamente.',
            'error_message' => null,
        ]);
    }

    public function test_process_document_has_fail_on_timeout_attribute(): void
    {
        $reflection = new \ReflectionClass(ProcessDocument::class);

        $attributes = $reflection->getAttributes(FailOnTimeout::class);

        $this->assertCount(1, $attributes);
    }
}
