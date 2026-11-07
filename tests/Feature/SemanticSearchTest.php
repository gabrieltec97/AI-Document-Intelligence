<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Search\SemanticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemanticSearchTest extends TestCase
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
        $chunkIndex = $document->chunks()->count();

        return DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'embedding' => $embedding,
            'embedding_model' => 'bge-m3',
            'embedded_at' => now(),
        ]);
    }

    public function test_search_returns_most_similar_chunks_first(): void
    {
        $user = User::factory()->create();

        $document = $this->createDocument($user, 'documento.pdf');

        $relevantChunk = $this->createChunk(
            $document,
            'Conteúdo muito relevante.',
            [1.0, 0.0, 0.0]
        );

        $this->createChunk(
            $document,
            'Conteúdo pouco relevante.',
            [0.0, 1.0, 0.0]
        );

        $embeddingService = $this->mock(EmbeddingService::class);

        $embeddingService->shouldReceive('embed')
            ->once()
            ->with(['Qual é o conteúdo relevante?'])
            ->andReturn([[1.0, 0.0, 0.0]]);

        $service = app(SemanticSearchService::class);

        $results = $service->search(
            $user,
            'Qual é o conteúdo relevante?'
        );

        $this->assertCount(2, $results);
        $this->assertSame($relevantChunk->id, $results->first()->id);
        $this->assertEquals(1.0, $results->first()->similarity);
    }

    public function test_search_does_not_return_other_users_chunks(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownDocument = $this->createDocument($user, 'meu.pdf');
        $otherDocument = $this->createDocument($otherUser, 'outro.pdf');

        $ownChunk = $this->createChunk(
            $ownDocument,
            'Meu conteúdo.',
            [1.0, 0.0, 0.0]
        );

        $this->createChunk(
            $otherDocument,
            'Conteúdo privado de outro usuário.',
            [1.0, 0.0, 0.0]
        );

        $embeddingService = $this->mock(EmbeddingService::class);

        $embeddingService->shouldReceive('embed')
            ->once()
            ->andReturn([[1.0, 0.0, 0.0]]);

        $results = app(SemanticSearchService::class)->search(
            $user,
            'Minha pergunta'
        );

        $this->assertCount(1, $results);
        $this->assertSame($ownChunk->id, $results->first()->id);
    }

    public function test_search_ignores_documents_that_are_not_processed(): void
    {
        $user = User::factory()->create();

        $document = $this->createDocument($user, 'pendente.pdf');

        $document->update(['status' => 'processing']);

        $this->createChunk(
            $document,
            'Conteúdo ainda não processado.',
            [1.0, 0.0, 0.0]
        );

        $embeddingService = $this->mock(EmbeddingService::class);

        $embeddingService->shouldNotReceive('embed');

        $results = app(SemanticSearchService::class)->search(
            $user,
            'Minha pergunta'
        );

        $this->assertCount(0, $results);
    }

    public function test_search_respects_result_limit(): void
    {
        $user = User::factory()->create();

        $document = $this->createDocument($user, 'documento.pdf');

        foreach (range(0, 5) as $index) {
            $this->createChunk(
                $document,
                "Conteúdo {$index}.",
                [1.0, 0.0, 0.0]
            );
        }

        $embeddingService = $this->mock(EmbeddingService::class);

        $embeddingService->shouldReceive('embed')
            ->once()
            ->andReturn([[1.0, 0.0, 0.0]]);

        $results = app(SemanticSearchService::class)->search(
            $user,
            'Minha pergunta',
            3
        );

        $this->assertCount(3, $results);
    }
}
