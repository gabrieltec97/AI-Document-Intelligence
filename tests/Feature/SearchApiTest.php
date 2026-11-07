<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    private function createDocument(User $user, string $name): Document
    {
        return Document::create([
            'user_id' => $user->id,
            'original_name' => $name,
            'file_path' => 'documents/' . $name,
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => 'processed',
        ]);
    }

    private function createChunk(
        Document $document,
        string $content,
        array $embedding
    ): DocumentChunk {
        return DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => $document->chunks()->count(),
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'embedding' => $embedding,
            'embedding_model' => 'bge-m3',
            'embedded_at' => now(),
        ]);
    }

    public function test_search_requires_authentication(): void
    {
        $response = $this->postJson('/api/search', [
            'query' => 'Minha pergunta',
        ]);

        $response->assertUnauthorized();
    }

    public function test_search_validates_query_and_limit(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/search', [
            'query' => 'oi',
            'limit' => 11,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['query', 'limit']);
    }

    public function test_search_returns_relevant_chunks(): void
    {
        $user = User::factory()->create();

        $document = $this->createDocument($user, 'meu-documento.pdf');

        $this->createChunk(
            $document,
            'Conteúdo relevante do documento.',
            [1.0, 0.0, 0.0]
        );

        $this->createChunk(
            $document,
            'Conteúdo pouco relevante.',
            [0.0, 1.0, 0.0]
        );

        $this->mock(EmbeddingService::class)
            ->shouldReceive('embed')
            ->once()
            ->with(['Qual é o conteúdo relevante?'])
            ->andReturn([[1.0, 0.0, 0.0]]);

        $response = $this->actingAs($user)->postJson('/api/search', [
            'query' => 'Qual é o conteúdo relevante?',
            'limit' => 5,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath(
                'results.0.document_name',
                'meu-documento.pdf'
            )
            ->assertJsonPath(
                'results.0.content',
                'Conteúdo relevante do documento.'
            );
    }

    public function test_search_does_not_expose_other_users_documents(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownDocument = $this->createDocument($user, 'meu.pdf');
        $otherDocument = $this->createDocument($otherUser, 'privado.pdf');

        $this->createChunk(
            $ownDocument,
            'Meu conteúdo.',
            [1.0, 0.0, 0.0]
        );

        $this->createChunk(
            $otherDocument,
            'Conteúdo privado.',
            [1.0, 0.0, 0.0]
        );

        $this->mock(EmbeddingService::class)
            ->shouldReceive('embed')
            ->once()
            ->andReturn([[1.0, 0.0, 0.0]]);

        $response = $this->actingAs($user)->postJson('/api/search', [
            'query' => 'Minha pergunta',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonMissing([
                'document_name' => 'privado.pdf',
            ]);
    }
}
