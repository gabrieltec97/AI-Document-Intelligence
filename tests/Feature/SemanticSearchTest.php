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
        array $embedding,
        ?string $section = null,
        ?string $company = null,
        ?string $position = null
    ): DocumentChunk {
        $chunkIndex = $document->chunks()->count();

        return DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'section' => $section,
            'company' => $company,
            'position' => $position,
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

    public function test_search_filters_chunks_by_mentioned_company(): void
    {
        $user = User::factory()->create();
        $document = $this->createDocument($user, 'curriculo.pdf');

        $cetChunk = $this->createChunk(
            $document,
            'Experiência profissional na Cet Logistics.',
            [1.0, 0.0, 0.0],
            'professional_experience',
            'Cet Logistics',
            'Analista de Sistemas / Desenvolvedor Web'
        );

        $this->createChunk(
            $document,
            'Experiência profissional na Porter Group.',
            [1.0, 0.0, 0.0],
            'professional_experience',
            'Porter Group',
            'Desenvolvedor Full-Stack PHP / Laravel'
        );

        $this->createChunk(
            $document,
            'Habilidades técnicas: PHP, Laravel e Docker.',
            [1.0, 0.0, 0.0],
            'technical_skills'
        );

        $embeddingService = $this->mock(EmbeddingService::class);

        $embeddingService->shouldReceive('embed')
            ->once()
            ->andReturn([[1.0, 0.0, 0.0]]);

        $results = app(SemanticSearchService::class)->search(
            $user,
            'Quais atividades foram realizadas na Cet Logistics?'
        );

        $this->assertCount(1, $results);
        $this->assertSame($cetChunk->id, $results->first()->id);
        $this->assertSame('Cet Logistics', $results->first()->company);
        $this->assertSame(
            'professional_experience',
            $results->first()->section
        );
    }

    public function test_search_filters_by_multiple_mentioned_companies(): void
    {
        $user = User::factory()->create();
        $document = $this->createDocument($user, 'curriculo.pdf');

        $cetChunk = $this->createChunk(
            $document,
            'Experiência na Cet Logistics.',
            [1.0, 0.0, 0.0],
            'professional_experience',
            'Cet Logistics'
        );

        $porterChunk = $this->createChunk(
            $document,
            'Experiência na Porter Group.',
            [0.9, 0.1, 0.0],
            'professional_experience',
            'Porter Group'
        );

        $this->createChunk(
            $document,
            'Experiência na V4 Company.',
            [1.0, 0.0, 0.0],
            'professional_experience',
            'V4 Company'
        );

        $embeddingService = $this->mock(EmbeddingService::class);

        $embeddingService->shouldReceive('embed')
            ->once()
            ->andReturn([[1.0, 0.0, 0.0]]);

        $results = app(SemanticSearchService::class)->search(
            $user,
            'Compare as experiências na Cet Logistics e na Porter Group.'
        );

        $this->assertCount(2, $results);

        $resultIds = $results->pluck('id')->all();

        $this->assertContains($cetChunk->id, $resultIds);
        $this->assertContains($porterChunk->id, $resultIds);

        $this->assertEqualsCanonicalizing(
            ['Cet Logistics', 'Porter Group'],
            $results->pluck('company')->all()
        );
    }

    public function test_search_preserves_each_mentioned_company_within_result_limit(): void
    {
        $user = User::factory()->create();
        $document = $this->createDocument($user, 'curriculo.pdf');

        $cetFirst = $this->createChunk(
            $document,
            'Primeira experiência na Cet Logistics.',
            [1.0, 0.0, 0.0],
            'professional_experience',
            'Cet Logistics'
        );

        $this->createChunk(
            $document,
            'Segunda experiência na Cet Logistics.',
            [0.99, 0.01, 0.0],
            'professional_experience',
            'Cet Logistics'
        );

        $this->createChunk(
            $document,
            'Terceira experiência na Cet Logistics.',
            [0.98, 0.02, 0.0],
            'professional_experience',
            'Cet Logistics'
        );

        $porter = $this->createChunk(
            $document,
            'Experiência profissional na Porter Group.',
            [0.8, 0.6, 0.0],
            'professional_experience',
            'Porter Group'
        );

        $embeddingService = $this->mock(EmbeddingService::class);

        $embeddingService->shouldReceive('embed')
            ->once()
            ->andReturn([[1.0, 0.0, 0.0]]);

        $results = app(SemanticSearchService::class)->search(
            $user,
            'Compare as experiências na Cet Logistics e na Porter Group.',
            2
        );

        $this->assertCount(2, $results);

        $companies = $results->pluck('company')->all();

        $this->assertContains('Cet Logistics', $companies);
        $this->assertContains('Porter Group', $companies);

        $this->assertContains(
            $porter->id,
            $results->pluck('id')->all()
        );

        $this->assertContains(
            $cetFirst->id,
            $results->pluck('id')->all()
        );
    }

    public function test_search_keeps_general_semantic_search_without_company_mention(): void
    {
        $user = User::factory()->create();
        $document = $this->createDocument($user, 'curriculo.pdf');

        $this->createChunk(
            $document,
            'Experiência na Cet Logistics.',
            [1.0, 0.0, 0.0],
            'professional_experience',
            'Cet Logistics'
        );

        $this->createChunk(
            $document,
            'Habilidades técnicas: PHP, Laravel e Docker.',
            [0.9, 0.1, 0.0],
            'technical_skills'
        );

        $this->createChunk(
            $document,
            'Projeto pessoal utilizando PHP.',
            [0.0, 1.0, 0.0],
            'projects'
        );

        $embeddingService = $this->mock(EmbeddingService::class);

        $embeddingService->shouldReceive('embed')
            ->once()
            ->andReturn([[1.0, 0.0, 0.0]]);

        $results = app(SemanticSearchService::class)->search(
            $user,
            'Quais tecnologias aparecem no currículo?'
        );

        $this->assertCount(3, $results);

        $this->assertSame(
            'Cet Logistics',
            $results->first()->company
        );
    }
}
