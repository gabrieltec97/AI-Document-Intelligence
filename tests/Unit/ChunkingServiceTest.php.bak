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
}
