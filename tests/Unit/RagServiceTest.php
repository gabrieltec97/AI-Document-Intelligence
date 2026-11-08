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

        $this->assertSame([
            'valid' => true,
            'has_citations' => false,
            'used' => [],
            'invalid' => [],
        ], $result['citation_validation']);
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

    public function test_it_includes_professional_metadata_in_the_prompt(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User();

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $chunk = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 0,
            'content' => 'Desenvolvimento de aplicações web utilizando PHP e Laravel.',
            'section' => 'professional_experience',
            'company' => 'Cet Logistics',
            'position' => 'Analista de Sistemas',
        ]);

        $chunk->id = 30;
        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.95;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($prompt) {
                return str_contains($prompt, 'Empresa: Cet Logistics')
                    && str_contains($prompt, 'Cargo: Analista de Sistemas')
                    && str_contains($prompt, 'Experiência profissional');
            }))
            ->andReturn('Resposta de teste [1].');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $service->ask($user, 'Quais atividades realizei na Cet Logistics?');
    }

    public function test_it_instructs_the_model_not_to_invent_comparisons(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User();

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $chunk = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 0,
            'content' => 'Desenvolvimento de aplicações utilizando PHP.',
            'section' => 'professional_experience',
            'company' => 'Cet Logistics',
            'position' => 'Desenvolvedor',
        ]);

        $chunk->id = 31;
        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.95;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($prompt) {
                return str_contains(
                    $prompt,
                    'Não invente informações nem estabeleça comparações que não sejam sustentadas pelo contexto.'
                );
            }))
            ->andReturn('Resposta de teste [1].');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $service->ask(
            $user,
            'Compare minhas experiências profissionais.'
        );
    }

    public function test_it_instructs_the_model_to_cite_factual_information(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User();

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $chunk = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 0,
            'content' => 'Desenvolvimento de aplicações utilizando PHP e Laravel.',
            'section' => 'professional_experience',
            'company' => 'Cet Logistics',
            'position' => 'Desenvolvedor',
        ]);

        $chunk->id = 32;
        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.95;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($prompt) {
                return str_contains(
                    $prompt,
                    'Toda afirmação factual deve apresentar a referência [n] da fonte que a comprova.'
                );
            }))
            ->andReturn('Desenvolvimento de aplicações com PHP e Laravel [1].');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $service->ask(
            $user,
            'Quais tecnologias foram utilizadas?'
        );
    }

    public function test_it_identifies_invalid_citations(): void
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
            'content' => 'O prazo é de 30 dias.',
        ]);

        $chunk->id = 25;
        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.95;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->andReturn('O prazo é de 30 dias [1]. Outra informação [3].');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask($user, 'Qual é o prazo?');

        $this->assertSame([
            'valid' => false,
            'has_citations' => true,
            'used' => [1, 3],
            'invalid' => [3],
        ], $result['citation_validation']);
    }

    public function test_it_accepts_repeated_valid_citations(): void
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
            'content' => 'O prazo é de 30 dias.',
        ]);

        $chunk->id = 25;
        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.95;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->andReturn('O prazo é de 30 dias [1]. O pagamento também [1].');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask($user, 'Qual é o prazo?');

        $this->assertSame([
            'valid' => true,
            'has_citations' => true,
            'used' => [1],
            'invalid' => [],
        ], $result['citation_validation']);
    }


    public function test_it_marks_answers_without_citations_as_incomplete(): void
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
            'content' => 'O prazo é de 30 dias.',
        ]);

        $chunk->id = 25;
        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.95;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->andReturn('Não encontrei informações suficientes.');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask($user, 'Qual é o prazo?');

        $this->assertSame([
            'valid' => false,
            'has_citations' => false,
            'used' => [],
            'invalid' => [],
        ], $result['citation_validation']);
    }

}
