<?php

namespace App\Services\Generation;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaGenerationService implements GenerationService
{
    public function generate(string $prompt): string
    {
        if (trim($prompt) === '') {
            throw new RuntimeException(
                'O prompt não pode estar vazio.'
            );
        }

        $response = Http::baseUrl(config('services.ollama.base_url'))
            ->timeout(config('services.ollama.generation_timeout'))
            ->post('/api/generate', [
                'model' => config('services.ollama.generation_model'),
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.2,
                    'num_predict' => 512,
                    'num_ctx' => 4096,
                ],
            ]);

        $response->throw();

        $generatedText = $response->json('response');

        if (!is_string($generatedText) || trim($generatedText) === '') {
            throw new RuntimeException(
                'O Ollama não retornou um texto válido.'
            );
        }

        return trim($generatedText);
    }
}
