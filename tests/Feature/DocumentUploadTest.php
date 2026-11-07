<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_does_not_expose_internal_document_fields(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/documents', [
                'document' => UploadedFile::fake()->create(
                    'teste.pdf',
                    100,
                    'application/pdf'
                ),
            ]);

        $response->assertCreated();

        $document = $response->json('document');

        $this->assertArrayHasKey('id', $document);
        $this->assertArrayHasKey('original_name', $document);
        $this->assertArrayHasKey('mime_type', $document);
        $this->assertArrayHasKey('file_size', $document);
        $this->assertArrayHasKey('status', $document);

        $this->assertArrayNotHasKey('user_id', $document);
        $this->assertArrayNotHasKey('file_path', $document);
        $this->assertArrayNotHasKey('error_message', $document);
        $this->assertArrayNotHasKey('extracted_text', $document);
    }


    public function test_upload_rejects_unsupported_file_types(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/documents', [
                'document' => UploadedFile::fake()->create(
                    'arquivo.txt',
                    100,
                    'text/plain'
                ),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('document');

        $this->assertDatabaseCount('documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_upload_requires_a_file(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/documents', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('document');

        $this->assertDatabaseCount('documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_upload_rejects_files_larger_than_20_mb(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/documents', [
                'document' => UploadedFile::fake()->create(
                    'documento-grande.pdf',
                    20481,
                    'application/pdf'
                ),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('document');

        $this->assertDatabaseCount('documents', 0);
        Queue::assertNothingPushed();
    }
}
