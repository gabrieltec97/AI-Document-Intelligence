<?php

namespace Tests\Unit;

use App\Services\Generation\OllamaGenerationService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OllamaGenerationServiceTest extends TestCase
{
    public function test_it_generates_text_using_ollama(): void
    {
        config([
            'services.ollama.base_url' => 'http://ollama.test',
            'services.ollama.generation_model' => 'qwen2.5:3b',
        ]);

        Http::fake([
            'ollama.test/api/generate' => Http::response([
                'response' => 'RAG combina recuperação e geração de informações.',
            ], 200),
        ]);

        $service = new OllamaGenerationService();

        $result = $service->generate('Explique o que é RAG.');

        $this->assertSame(
            'RAG combina recuperação e geração de informações.',
            $result
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'http://ollama.test/api/generate'
                && $request['model'] === 'qwen2.5:3b'
                && $request['prompt'] === 'Explique o que é RAG.'
                && $request['stream'] === false
                && $request['options']['temperature'] === 0.2
                && $request['options']['num_predict'] === 512
                && $request['options']['num_ctx'] === 4096;
        });
    }

    public function test_it_rejects_empty_prompts(): void
    {
        $service = new OllamaGenerationService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('O prompt não pode estar vazio.');

        $service->generate('   ');
    }

    public function test_it_rejects_empty_generation_responses(): void
    {
        config([
            'services.ollama.base_url' => 'http://ollama.test',
            'services.ollama.generation_model' => 'qwen2.5:3b',
        ]);

        Http::fake([
            'ollama.test/api/generate' => Http::response([
                'response' => '',
            ], 200),
        ]);

        $service = new OllamaGenerationService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('O Ollama não retornou um texto válido.');

        $service->generate('Explique RAG.');
    }

    public function test_it_throws_when_ollama_returns_an_http_error(): void
    {
        config([
            'services.ollama.base_url' => 'http://ollama.test',
            'services.ollama.generation_model' => 'qwen2.5:3b',
        ]);

        Http::fake([
            'ollama.test/api/generate' => Http::response([
                'error' => 'Model not found',
            ], 404),
        ]);

        $service = new OllamaGenerationService();

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $service->generate('Explique RAG.');
    }
}
