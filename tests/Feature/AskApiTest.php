<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Generation\GenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AskApiTest extends TestCase
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

    public function test_ask_requires_authentication(): void
    {
        $response = $this->postJson('/api/ask', [
            'query' => 'Minha pergunta',
        ]);

        $response->assertUnauthorized();
    }

    public function test_ask_validates_query_and_limit(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/ask', [
            'query' => 'oi',
            'limit' => 11,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['query', 'limit']);
    }

    public function test_ask_returns_answer_and_sources(): void
    {
        $user = User::factory()->create();

        $this->mock(\App\Services\Rag\RagService::class)
            ->shouldReceive('ask')
            ->once()
            ->with($user, 'Qual é o prazo?', 3)
            ->andReturn([
                'answer' => 'O prazo é de 30 dias [1].',
                'sources' => [
                    [
                        'document_id' => 10,
                        'document_name' => 'contrato.pdf',
                        'chunk_id' => 25,
                        'chunk_index' => 0,
                        'similarity' => 0.95,
                    ],
                ],
            ]);

        $response = $this->actingAs($user)->postJson('/api/ask', [
            'query' => 'Qual é o prazo?',
            'limit' => 3,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('query', 'Qual é o prazo?')
            ->assertJsonPath('answer', 'O prazo é de 30 dias [1].')
            ->assertJsonPath('total_sources', 1)
            ->assertJsonPath('sources.0.document_name', 'contrato.pdf')
            ->assertJsonPath('sources.0.similarity', 0.95);
    }

    public function test_ask_does_not_use_other_users_documents(): void
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
            ->with(['Minha pergunta'])
            ->andReturn([[1.0, 0.0, 0.0]]);

        $this->mock(GenerationService::class)
            ->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function (string $prompt): bool {
                return str_contains($prompt, 'Meu conteúdo.')
                    && !str_contains($prompt, 'Conteúdo privado.');
            }))
            ->andReturn('Resposta baseada no meu documento [1].');

        $response = $this->actingAs($user)->postJson('/api/ask', [
            'query' => 'Minha pergunta',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('total_sources', 1)
            ->assertJsonPath('sources.0.document_name', 'meu.pdf')
            ->assertJsonMissing([
                'document_name' => 'privado.pdf',
            ]);
    }
}
