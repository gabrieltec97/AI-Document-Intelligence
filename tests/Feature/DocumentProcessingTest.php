<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\Ocr\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Illuminate\Queue\Attributes\FailOnTimeout;

class DocumentProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
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

        (new ProcessDocument($document->id))->handle($ocr);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
            'extracted_text' => 'Texto extraído do documento.',
            'error_message' => null,
        ]);
    }

    public function test_missing_file_marks_document_as_failed(): void
    {
        $document = $this->createDocument();

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldNotReceive('extractText');

        try {
            (new ProcessDocument($document->id))->handle($ocr);

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
            (new ProcessDocument($document->id))->handle($ocr);

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

    public function test_already_processed_document_is_not_processed_again(): void
    {
        $document = $this->createDocument('processed');

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldNotReceive('extractText');

        (new ProcessDocument($document->id))->handle($ocr);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
        ]);
    }

    public function test_nonexistent_document_is_ignored(): void
    {
        $ocr = $this->mock(OcrService::class);

        $ocr->shouldNotReceive('extractText');

        (new ProcessDocument(999999))->handle($ocr);

        $this->assertDatabaseMissing('documents', [
            'id' => 999999,
        ]);
    }


    public function test_document_already_processing_is_not_processed_again(): void
    {
        $document = $this->createDocument('processing');

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldNotReceive('extractText');

        (new ProcessDocument($document->id))->handle($ocr);

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

        Storage::disk('local')->put(
            $document->file_path,
            'conteudo de teste'
        );

        $ocr = $this->mock(OcrService::class);

        $ocr->shouldReceive('extractText')
            ->once()
            ->with(Storage::path($document->file_path))
            ->andReturn('Texto extraído após nova tentativa.');

        (new ProcessDocument($document->id))->handle($ocr);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processed',
            'extracted_text' => 'Texto extraído após nova tentativa.',
            'error_message' => null,
        ]);
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
