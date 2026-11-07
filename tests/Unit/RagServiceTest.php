<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Generation\GenerationService;
use App\Services\Rag\RagService;
use App\Services\Search\SemanticSearchService;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class RagServiceTest extends TestCase
{
    public function test_it_returns_message_when_no_chunks_are_found(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User();

        $searchService
            ->shouldReceive('search')
            ->once()
            ->with($user, 'Qual é o prazo?', 5)
            ->andReturn(collect());

        $generationService
            ->shouldNotReceive('generate');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask($user, 'Qual é o prazo?');

        $this->assertSame(
            'Não encontrei conteúdo nos seus documentos processados para responder a essa pergunta.',
            $result['answer']
        );

        $this->assertSame([], $result['sources']);
    }

    public function test_it_generates_answer_using_retrieved_chunks(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User();

        $document = new Document([
            'original_name' => 'contrato.pdf',
        ]);

        $chunk = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 0,
            'content' => 'O prazo de pagamento é de 30 dias.',
        ]);

        $chunk->id = 25;
        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.95;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->with($user, 'Qual é o prazo de pagamento?', 5)
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($prompt) {
                return str_contains($prompt, 'O prazo de pagamento é de 30 dias.')
                    && str_contains($prompt, 'Qual é o prazo de pagamento?')
                    && str_contains($prompt, '[1]');
            }))
            ->andReturn('O prazo de pagamento é de 30 dias [1].');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask(
            $user,
            'Qual é o prazo de pagamento?'
        );

        $this->assertSame(
            'O prazo de pagamento é de 30 dias [1].',
            $result['answer']
        );

        $this->assertCount(1, $result['sources']);

        $this->assertSame(10, $result['sources'][0]['document_id']);
        $this->assertSame('contrato.pdf', $result['sources'][0]['document_name']);
        $this->assertSame(25, $result['sources'][0]['chunk_id']);
        $this->assertSame(0, $result['sources'][0]['chunk_index']);
        $this->assertSame(0.95, $result['sources'][0]['similarity']);
    }

    public function test_it_respects_the_requested_chunk_limit(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User();

        $searchService
            ->shouldReceive('search')
            ->once()
            ->with($user, 'Pergunta de teste?', 3)
            ->andReturn(collect());

        $generationService
            ->shouldNotReceive('generate');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $service->ask($user, 'Pergunta de teste?', 3);

        $this->assertTrue(true);
    }

    public function test_it_propagates_generation_errors(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User();

        $document = new Document([
            'original_name' => 'contrato.pdf',
        ]);

        $chunk = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 0,
            'content' => 'Conteúdo de teste.',
        ]);

        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.90;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('Falha no Ollama.'));

        $service = new RagService(
            $searchService,
            $generationService
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Falha no Ollama.');

        $service->ask($user, 'Pergunta de teste?');
    }
}
