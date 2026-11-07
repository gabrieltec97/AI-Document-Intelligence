<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createDocument(
        User $user,
        string $name,
        string $text = ''
    ): Document {
        return Document::create([
            'user_id' => $user->id,
            'original_name' => $name,
            'file_path' => 'documents/' . $name,
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => 'processed',
            'extracted_text' => $text,
        ]);
    }

    public function test_user_can_list_own_documents_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownDocument = $this->createDocument($user, 'meu-documento.pdf');
        $this->createDocument($otherUser, 'documento-alheio.pdf');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/documents');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'id' => $ownDocument->id,
                'original_name' => 'meu-documento.pdf',
            ])
            ->assertJsonMissing([
                'original_name' => 'documento-alheio.pdf',
            ]);
    }

    public function test_user_can_view_own_document(): void
    {
        $user = User::factory()->create();

        $document = $this->createDocument(
            $user,
            'meu-documento.pdf',
            'Conteúdo extraído do meu documento.'
        );

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/documents/{$document->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath(
                'data.extracted_text',
                'Conteúdo extraído do meu documento.'
            );
    }

    public function test_user_cannot_view_another_users_document(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $document = $this->createDocument(
            $owner,
            'documento-privado.pdf',
            'Conteúdo privado.'
        );

        $response = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/documents/{$document->id}");

        $response->assertNotFound();
    }

    public function test_user_cannot_list_another_users_documents(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->createDocument($otherUser, 'documento-privado.pdf');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/documents');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'original_name' => 'documento-privado.pdf',
            ]);
    }
}
