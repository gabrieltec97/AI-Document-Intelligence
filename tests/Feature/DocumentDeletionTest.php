<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_user_can_delete_own_document(): void
    {
        $user = User::factory()->create();

        $path = 'documents/teste.pdf';

        Storage::disk('local')->put($path, 'conteudo de teste');

        $document = Document::create([
            'user_id' => $user->id,
            'original_name' => 'teste.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 16,
            'status' => 'processed',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/documents/{$document->id}");

        $response->assertOk()
            ->assertJson([
                'message' => 'Documento excluído com sucesso.',
            ]);

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_user_cannot_delete_another_users_document(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $path = 'documents/documento-alheio.pdf';

        Storage::disk('local')->put($path, 'conteudo de teste');

        $document = Document::create([
            'user_id' => $owner->id,
            'original_name' => 'documento-alheio.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 16,
            'status' => 'processed',
        ]);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->deleteJson("/api/documents/{$document->id}");

        $response->assertNotFound();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'user_id' => $owner->id,
        ]);

        Storage::disk('local')->assertExists($path);
    }

    public function test_user_cannot_delete_document_being_processed(): void
    {
        $user = User::factory()->create();

        $path = 'documents/processando.pdf';

        Storage::disk('local')->put($path, 'conteudo de teste');

        $document = Document::create([
            'user_id' => $user->id,
            'original_name' => 'processando.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 16,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/documents/{$document->id}");

        $response->assertStatus(409);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processing',
        ]);

        Storage::disk('local')->assertExists($path);
    }
}
