<?php

namespace Tests\Unit;

use App\Services\Chunking\ChunkingService;
use PHPUnit\Framework\TestCase;

class ChunkingServiceTest extends TestCase
{
    private ChunkingService $chunkingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chunkingService = new ChunkingService();
    }

    public function test_empty_text_returns_empty_array(): void
    {
        $this->assertSame(
            [],
            $this->chunkingService->split('')
        );
    }

    public function test_short_text_returns_single_chunk(): void
    {
        $text = 'Texto curto para processamento.';

        $this->assertSame(
            [$text],
            $this->chunkingService->split($text)
        );
    }

    public function test_long_text_is_divided_into_multiple_chunks(): void
    {
        $text = str_repeat('Este é um texto de teste. ', 150);

        $chunks = $this->chunkingService->split($text);

        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_chunks_respect_maximum_size(): void
    {
        $text = str_repeat('Palavra teste conteúdo. ', 200);

        $chunks = $this->chunkingService->split($text);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(
                1500,
                mb_strlen($chunk, 'UTF-8')
            );
        }
    }

    public function test_chunks_preserve_utf8_characters(): void
    {
        $text = str_repeat(
            'Informação, documentação, configuração e integração. ',
            100
        );

        $chunks = $this->chunkingService->split($text);

        foreach ($chunks as $chunk) {
            $this->assertTrue(
                mb_check_encoding($chunk, 'UTF-8')
            );
        }
    }


    public function test_chunks_have_overlap(): void
    {
        $words = [];

        for ($i = 1; $i <= 300; $i++) {
            $words[] = 'palavra' . $i;
        }

        $text = implode(' ', $words);

        $chunks = $this->chunkingService->split($text);

        $this->assertGreaterThanOrEqual(2, count($chunks));

        // Os últimos 100 caracteres do primeiro bloco
        // devem aparecer no segundo bloco.
        $overlap = mb_substr($chunks[0], -100, null, 'UTF-8');

        $this->assertStringContainsString(
            $overlap,
            $chunks[1]
        );

        // Não deve gerar blocos idênticos consecutivos.
        $this->assertNotSame($chunks[0], $chunks[1]);
    }

    public function test_resume_sections_are_preserved_in_separate_chunks(): void
    {
        $text = implode("\n\n", [
            'OBJETIVO PROFISSIONAL',
            str_repeat('Objetivo profissional. ', 20),
            'HABILIDADES TÉCNICAS & FERRAMENTAS',
            str_repeat('PHP, Laravel, JavaScript e Docker. ', 10),
            'EXPERIÊNCIA PROFISSIONAL',
            'Desenvolvedor | Empresa A 2023 — 2025',
            str_repeat('Experiência profissional com PHP e Laravel. ', 10),
            'Desenvolvedor | Empresa B 2020 — 2023',
            str_repeat('Experiência profissional com JavaScript e MySQL. ', 10),
            'PROJETOS PESSOAIS & OPEN SOURCE',
            str_repeat('Projeto pessoal com PHP e MySQL. ', 10),
            'FORMAÇÃO ACADÊMICA',
            'Bacharelado em Sistemas de Informação.',
        ]);

        $chunks = $this->chunkingService->split($text);

        $this->assertGreaterThan(1, count($chunks));

        $this->assertTrue(
            collect($chunks)->contains(
                fn (string $chunk) => str_contains($chunk, 'HABILIDADES TÉCNICAS')
            )
        );

        $this->assertTrue(
            collect($chunks)->contains(
                fn (string $chunk) => str_contains($chunk, 'PROJETOS PESSOAIS')
            )
        );
    }

    public function test_professional_experiences_are_not_mixed_with_other_sections(): void
    {
        $text = implode("\n\n", [
            'HABILIDADES TÉCNICAS & FERRAMENTAS',
            'PHP, Laravel, JavaScript, Docker e MySQL.',
            'EXPERIÊNCIA PROFISSIONAL',
            'Desenvolvedor | Porter Group 2023 — 2025',
            'Desenvolvimento de aplicações utilizando PHP, Laravel e Bootstrap.',
            'Desenvolvedor | V4 Company 2019 — 2023',
            'Desenvolvimento de aplicações utilizando JavaScript e MySQL.',
            'PROJETOS PESSOAIS & OPEN SOURCE',
            'Projeto pessoal utilizando PHP e Laravel.',
        ]);

        $chunks = $this->chunkingService->split($text);

        $porterChunk = collect($chunks)->first(
            fn (string $chunk) => str_contains($chunk, 'Porter Group')
        );

        $v4Chunk = collect($chunks)->first(
            fn (string $chunk) => str_contains($chunk, 'V4 Company')
        );

        $this->assertNotNull($porterChunk);
        $this->assertNotNull($v4Chunk);
        $this->assertStringContainsString('Laravel e Bootstrap', $porterChunk);
        $this->assertStringContainsString('JavaScript e MySQL', $v4Chunk);
    }

    public function test_resume_section_titles_are_not_lost(): void
    {
        $text = implode("\n", [
            'HABILIDADES TÉCNICAS & FERRAMENTAS',
            'PHP, Laravel e JavaScript.',
            'EXPERIÊNCIA PROFISSIONAL',
            'Desenvolvedor | Empresa A',
            'PHP e Laravel.',
            'PROJETOS PESSOAIS & OPEN SOURCE',
            'Projeto pessoal em PHP.',
        ]);

        $chunks = $this->chunkingService->split($text);

        $combined = implode("\n", $chunks);

        $this->assertStringContainsString('HABILIDADES TÉCNICAS', $combined);
        $this->assertStringContainsString('EXPERIÊNCIA PROFISSIONAL', $combined);
        $this->assertStringContainsString('PROJETOS PESSOAIS', $combined);
    }

    public function test_each_professional_experience_is_kept_in_its_own_chunk(): void
    {
        $text = implode("\n", [
            'EXPERIÊNCIA PROFISSIONAL',
            'Analista de Sistemas / Desenvolvedor Web | Cet Logistics 2025 — 2026',
            'Desenvolvimento utilizando PHP e Docker.',
            'Desenvolvedor Full-Stack PHP / Laravel | Porter Group 2023 — 2025',
            'Desenvolvimento utilizando PHP, Laravel e Bootstrap.',
            'Desenvolvedor Full-Stack | V4 Company 2019 — 2023',
            'Desenvolvimento utilizando JavaScript e MySQL.',
            'Estagiário em Desenvolvimento Web | Neosyx Desenvolvimento 2018-2018',
            'Desenvolvimento utilizando HTML5, CSS3 e JavaScript.',
        ]);

        $chunks = $this->chunkingService->split($text);

        $companies = [
            'Cet Logistics',
            'Porter Group',
            'V4 Company',
            'Neosyx Desenvolvimento',
        ];

        foreach ($companies as $company) {
            $matchingChunks = array_filter(
                $chunks,
                fn (string $chunk) => str_contains($chunk, $company)
            );

            $this->assertCount(1, $matchingChunks);
        }

        foreach ($chunks as $chunk) {
            $companyCount = collect($companies)
                ->filter(fn (string $company) => str_contains($chunk, $company))
                ->count();

            $this->assertLessThanOrEqual(1, $companyCount);
        }
    }


    public function test_split_with_metadata_identifies_professional_experience(): void
    {
        $text = implode("\n", [
            'HABILIDADES TÉCNICAS & FERRAMENTAS',
            'PHP, Laravel, JavaScript e Docker.',
            'EXPERIÊNCIA PROFISSIONAL',
            'Analista de Sistemas / Desenvolvedor Web | Cet Logistics 2025 — 2026',
            'Desenvolvimento utilizando PHP e Docker.',
            'Desenvolvedor Full-Stack PHP / Laravel | Porter Group 2023 — 2025',
            'Desenvolvimento utilizando PHP, Laravel e Bootstrap.',
        ]);

        $chunks = $this->chunkingService->splitWithMetadata($text);

        $cetChunks = array_values(array_filter(
            $chunks,
            fn (array $chunk) => $chunk['company'] === 'Cet Logistics'
        ));

        $this->assertCount(1, $cetChunks);

        $this->assertSame(
            'professional_experience',
            $cetChunks[0]['section']
        );

        $this->assertSame(
            'Analista de Sistemas / Desenvolvedor Web',
            $cetChunks[0]['position']
        );

        $this->assertStringContainsString(
            'Desenvolvimento utilizando PHP e Docker.',
            $cetChunks[0]['content']
        );
    }

    public function test_split_with_metadata_does_not_assign_company_to_other_sections(): void
    {
        $text = implode("\n", [
            'HABILIDADES TÉCNICAS & FERRAMENTAS',
            'PHP, Laravel, JavaScript e Docker.',
            'EXPERIÊNCIA PROFISSIONAL',
            'Desenvolvedor | Cet Logistics 2025 — 2026',
            'Desenvolvimento utilizando PHP e Docker.',
            'PROJETOS PESSOAIS & OPEN SOURCE',
            'Projeto pessoal utilizando PHP e Laravel.',
            'FORMAÇÃO ACADÊMICA',
            'Bacharelado em Sistemas de Informação.',
        ]);

        $chunks = $this->chunkingService->splitWithMetadata($text);

        $skillsChunk = null;
        $projectsChunk = null;
        $educationChunk = null;

        foreach ($chunks as $chunk) {
            if (str_contains($chunk['content'], 'HABILIDADES TÉCNICAS')) {
                $skillsChunk = $chunk;
            }

            if (str_contains($chunk['content'], 'PROJETOS PESSOAIS')) {
                $projectsChunk = $chunk;
            }

            if (str_contains($chunk['content'], 'FORMAÇÃO ACADÊMICA')) {
                $educationChunk = $chunk;
            }
        }

        $this->assertNotNull($skillsChunk);
        $this->assertNotNull($projectsChunk);
        $this->assertNotNull($educationChunk);

        $this->assertSame('technical_skills', $skillsChunk['section']);
        $this->assertSame('projects', $projectsChunk['section']);
        $this->assertSame('education', $educationChunk['section']);

        $this->assertNull($skillsChunk['company']);
        $this->assertNull($skillsChunk['position']);

        $this->assertNull($projectsChunk['company']);
        $this->assertNull($projectsChunk['position']);

        $this->assertNull($educationChunk['company']);
        $this->assertNull($educationChunk['position']);
    }

}
