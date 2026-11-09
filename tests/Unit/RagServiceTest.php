<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Generation\GenerationService;
use App\Services\Rag\RagService;
use App\Services\Search\SemanticSearchService;
use Mockery;
use Tests\TestCase;

class RagServiceTest extends TestCase
{
    public function test_it_returns_message_when_no_chunks_are_found(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

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
            'uncited_items' => [],
            'abstained' => true,
        ], $result['citation_validation']);
    }

    public function test_it_generates_answer_using_retrieved_chunks(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

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

        $user = new User;

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

        $user = new User;

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

        $user = new User;

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

        $user = new User;

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

        $user = new User;

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
                    'REGRA OBRIGATÓRIA: nenhuma afirmação factual pode aparecer sem uma citação.'
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

        $user = new User;

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
            'uncited_items' => [],
            'abstained' => false,
        ], $result['citation_validation']);
    }

    public function test_it_accepts_repeated_valid_citations(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

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
            'uncited_items' => [],
            'abstained' => false,
        ], $result['citation_validation']);
    }

    public function test_it_rejects_lists_with_uncited_items(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $chunks = collect();

        foreach ([40, 41] as $id) {
            $chunk = new DocumentChunk([
                'document_id' => 10,
                'chunk_index' => $id - 40,
                'content' => 'Informação documentada.',
            ]);

            $chunk->id = $id;
            $chunk->setRelation('document', $document);
            $chunk->similarity = 0.95;

            $chunks->push($chunk);
        }

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn($chunks);

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->andReturn("- PHP [1]\n- Docker");

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask(
            $user,
            'Quais tecnologias foram utilizadas?'
        );

        $this->assertFalse(
            $result['citation_validation']['valid']
        );
    }

    public function test_it_marks_answers_without_citations_as_incomplete(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

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
            'uncited_items' => [],
            'abstained' => false,
        ], $result['citation_validation']);
    }

    public function test_it_accepts_explicit_abstentions_without_citations(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $chunk = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 0,
            'content' => 'PHP, Laravel e Docker.',
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
            ->andReturn(
                'Não foram encontradas informações sobre certificações AWS, Azure ou Kubernetes no seu currículo.'
            );

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask(
            $user,
            'Quais certificações constam no currículo?'
        );

        $this->assertSame([
            'valid' => true,
            'has_citations' => false,
            'used' => [],
            'invalid' => [],
            'uncited_items' => [],
            'abstained' => true,
        ], $result['citation_validation']);
    }

    public function test_it_adds_citations_when_only_one_source_exists(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $chunk = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 0,
            'content' => 'PHP, Docker e APIs RESTful.',
        ]);

        $chunk->id = 40;
        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.95;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->andReturn(
                "Tecnologias utilizadas:\n\n- PHP\n- Docker\n- APIs RESTful"
            );

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask($user, 'Quais tecnologias foram utilizadas?');

        $this->assertSame(
            "Tecnologias utilizadas:\n\n- PHP [1]\n- Docker [1]\n- APIs RESTful [1]",
            $result['answer']
        );

        $this->assertSame([
            'valid' => true,
            'has_citations' => true,
            'used' => [1],
            'invalid' => [],
            'uncited_items' => [],
            'abstained' => false,
        ], $result['citation_validation']);
    }

    public function test_it_does_not_add_citations_when_multiple_sources_exist(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $chunks = collect();

        foreach ([40, 41] as $id) {
            $chunk = new DocumentChunk([
                'document_id' => 10,
                'chunk_index' => $id - 40,
                'content' => 'Informação documentada.',
            ]);

            $chunk->id = $id;
            $chunk->setRelation('document', $document);
            $chunk->similarity = 0.95;

            $chunks->push($chunk);
        }

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn($chunks);

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->andReturn("- PHP\n- Docker");

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask($user, 'Quais tecnologias foram utilizadas?');

        $this->assertSame(
            "- PHP\n- Docker",
            $result['answer']
        );

        $this->assertSame([
            'valid' => false,
            'has_citations' => false,
            'used' => [],
            'invalid' => [],
            'uncited_items' => [
                '- PHP',
                '- Docker',
            ],
            'abstained' => false,
        ], $result['citation_validation']);
    }

    public function test_it_builds_professional_comparisons_without_calling_generation_service(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $cet = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 4,
            'content' => "Experiência na Cet Logistics.\n* Desenvolvimento com PHP e APIs RESTful.\n* Utilização de Docker.",
            'section' => 'professional_experience',
            'company' => 'Cet Logistics',
            'position' => 'Analista de Sistemas',
        ]);

        $cet->id = 21;
        $cet->setRelation('document', $document);
        $cet->similarity = 0.95;

        $porter = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 5,
            'content' => "Experiência na Porter Group.\n* Desenvolvimento com PHP (Laravel) e JavaScript.\n* Utilização de Docker e Git.",
            'section' => 'professional_experience',
            'company' => 'Porter Group',
            'position' => 'Desenvolvedor Full-Stack',
        ]);

        $porter->id = 22;
        $porter->setRelation('document', $document);
        $porter->similarity = 0.90;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->with(
                $user,
                'Compare minhas experiências profissionais na Cet Logistics e na Porter Group.',
                5
            )
            ->andReturn(collect([$cet, $porter]));

        $generationService
            ->shouldNotReceive('generate');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask(
            $user,
            'Compare minhas experiências profissionais na Cet Logistics e na Porter Group.'
        );

        $this->assertStringContainsString(
            'Cet Logistics',
            $result['answer']
        );

        $this->assertStringContainsString(
            'Porter Group',
            $result['answer']
        );

        $this->assertStringContainsString(
            'Desenvolvimento com PHP e APIs RESTful.',
            $result['answer']
        );

        $this->assertStringContainsString(
            'Desenvolvimento com PHP (Laravel) e JavaScript.',
            $result['answer']
        );

        $this->assertStringContainsString('[1]', $result['answer']);
        $this->assertStringContainsString('[2]', $result['answer']);

        $this->assertSame([
            'valid' => true,
            'has_citations' => true,
            'used' => [1, 2],
            'invalid' => [],
            'uncited_items' => [],
            'abstained' => false,
        ], $result['citation_validation']);

        $this->assertCount(2, $result['sources']);
    }

    public function test_it_preserves_paragraphs_and_bullets_in_professional_comparisons(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $cet = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 4,
            'content' => "Atuação na manutenção de sistemas legados.\n\n- Desenvolvimento com PHP e Laravel.\n- Integração com APIs REST.\n\nParticipação em decisões de arquitetura e melhorias técnicas.",
            'section' => 'professional_experience',
            'company' => 'Cet Logistics',
            'position' => 'Analista de Sistemas',
        ]);

        $cet->id = 21;
        $cet->setRelation('document', $document);
        $cet->similarity = 0.95;

        $porter = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 5,
            'content' => '- Desenvolvimento com JavaScript.',
            'section' => 'professional_experience',
            'company' => 'Porter Group',
            'position' => 'Desenvolvedor Full-Stack',
        ]);

        $porter->id = 22;
        $porter->setRelation('document', $document);
        $porter->similarity = 0.90;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$cet, $porter]));

        $generationService->shouldNotReceive('generate');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask(
            $user,
            'Compare minhas experiências na Cet Logistics e na Porter Group.'
        );

        $this->assertStringContainsString(
            'Atuação na manutenção de sistemas legados. [1]',
            $result['answer']
        );

        $this->assertStringContainsString(
            '- Desenvolvimento com PHP e Laravel. [1]',
            $result['answer']
        );

        $this->assertStringContainsString(
            '- Integração com APIs REST. [1]',
            $result['answer']
        );

        $this->assertStringContainsString(
            'Participação em decisões de arquitetura e melhorias técnicas. [1]',
            $result['answer']
        );

        $this->assertStringContainsString(
            '- Desenvolvimento com JavaScript. [2]',
            $result['answer']
        );

        $this->assertTrue($result['citation_validation']['valid']);
    }

    public function test_it_keeps_generation_service_for_non_comparison_questions(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $chunk = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 4,
            'content' => 'Desenvolvimento com PHP e APIs RESTful.',
            'section' => 'professional_experience',
            'company' => 'Cet Logistics',
            'position' => 'Analista de Sistemas',
        ]);

        $chunk->id = 21;
        $chunk->setRelation('document', $document);
        $chunk->similarity = 0.95;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([$chunk]));

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->andReturn('A experiência utilizou PHP e APIs RESTful [1].');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask(
            $user,
            'Quais tecnologias foram utilizadas na Cet Logistics?'
        );

        $this->assertSame(
            'A experiência utilizou PHP e APIs RESTful [1].',
            $result['answer']
        );

        $this->assertTrue(
            $result['citation_validation']['valid']
        );
    }

    public function test_it_groups_professional_chunks_and_preserves_individual_citations(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

        $document = new Document([
            'original_name' => 'curriculo.pdf',
        ]);

        $cetFirst = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 4,
            'content' => "* Desenvolvimento com PHP.\n  Criação de APIs RESTful.",
            'section' => 'professional_experience',
            'company' => 'Cet Logistics',
            'position' => 'Analista de Sistemas',
        ]);

        $cetFirst->id = 21;
        $cetFirst->setRelation('document', $document);
        $cetFirst->similarity = 0.95;

        $cetSecond = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 5,
            'content' => '- Utilização de Docker e revisão de código.',
            'section' => 'professional_experience',
            'company' => 'Cet Logistics',
            'position' => null,
        ]);

        $cetSecond->id = 22;
        $cetSecond->setRelation('document', $document);
        $cetSecond->similarity = 0.90;

        $porter = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 6,
            'content' => "+ Desenvolvimento com Laravel.\n+ Utilização de Git.",
            'section' => 'professional_experience',
            'company' => 'Porter Group',
            'position' => 'Desenvolvedor Full-Stack',
        ]);

        $porter->id = 23;
        $porter->setRelation('document', $document);
        $porter->similarity = 0.85;

        $skills = new DocumentChunk([
            'document_id' => 10,
            'chunk_index' => 7,
            'content' => 'Conhecimentos gerais em JavaScript e MySQL.',
            'section' => 'technical_skills',
        ]);

        $skills->id = 24;
        $skills->setRelation('document', $document);
        $skills->similarity = 0.80;

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn(collect([
                $cetFirst,
                $cetSecond,
                $porter,
                $skills,
            ]));

        $generationService
            ->shouldNotReceive('generate');

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask(
            $user,
            'Compare minhas experiências profissionais.'
        );

        $answer = $result['answer'];

        // A empresa aparece uma única vez como título.
        $this->assertSame(
            1,
            substr_count($answer, '### Cet Logistics')
        );

        $this->assertSame(
            1,
            substr_count($answer, '### Porter Group')
        );

        // Os conteúdos dos dois chunks da Cet são preservados.
        $this->assertStringContainsString(
            'Desenvolvimento com PHP.',
            $answer
        );

        $this->assertStringContainsString(
            'Criação de APIs RESTful.',
            $answer
        );

        $this->assertStringContainsString(
            'Utilização de Docker e revisão de código.',
            $answer
        );

        // O conteúdo da Porter também é preservado.
        $this->assertStringContainsString(
            'Desenvolvimento com Laravel.',
            $answer
        );

        $this->assertStringContainsString(
            'Utilização de Git.',
            $answer
        );

        $this->assertStringContainsString(
            'Desenvolvimento com PHP. Criação de APIs RESTful. [1]',
            $answer
        );

        $this->assertStringContainsString(
            'Utilização de Docker e revisão de código. [2]',
            $answer
        );

        $this->assertStringContainsString(
            'Desenvolvimento com Laravel. [3]',
            $answer
        );

        // Habilidades gerais não são atribuídas às empresas.
        $this->assertStringNotContainsString(
            'Conhecimentos gerais em JavaScript e MySQL.',
            $answer
        );

        $this->assertSame(
            2,
            substr_count($answer, '**Cargo:**')
        );

        $this->assertStringNotContainsString(
            '**Cargo:** [2]',
            $answer
        );

        $this->assertSame([
            'valid' => true,
            'has_citations' => true,
            'used' => [1, 2, 3],
            'invalid' => [],
            'uncited_items' => [],
            'abstained' => false,
        ], $result['citation_validation']);

        $this->assertCount(4, $result['sources']);
    }

    public function test_it_rejects_uncited_factual_paragraphs(): void
    {
        $searchService = Mockery::mock(SemanticSearchService::class);
        $generationService = Mockery::mock(GenerationService::class);

        $user = new User;

        $document = new Document([
            'original_name' => 'contrato.pdf',
        ]);

        $chunks = collect();

        foreach ([40, 41] as $id) {
            $chunk = new DocumentChunk([
                'document_id' => 10,
                'chunk_index' => $id - 40,
                'content' => 'Informação documentada.',
            ]);

            $chunk->id = $id;
            $chunk->setRelation('document', $document);
            $chunk->similarity = 0.95;

            $chunks->push($chunk);
        }

        $searchService
            ->shouldReceive('search')
            ->once()
            ->andReturn($chunks);

        $generationService
            ->shouldReceive('generate')
            ->once()
            ->andReturn(
                "O prazo é de 30 dias [1].\n\nA multa é de 10%."
            );

        $service = new RagService(
            $searchService,
            $generationService
        );

        $result = $service->ask(
            $user,
            'Quais são as condições do contrato?'
        );

        $this->assertSame([
            'valid' => false,
            'has_citations' => true,
            'used' => [1],
            'invalid' => [],
            'uncited_items' => [
                'A multa é de 10%.',
            ],
            'abstained' => false,
        ], $result['citation_validation']);
    }
}
