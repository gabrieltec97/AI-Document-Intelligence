<?php

namespace App\Services\Embeddings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaEmbeddingService implements EmbeddingService
{
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = Http::baseUrl(config('services.ollama.base_url'))
            ->timeout(120)
            ->post('/api/embed', [
                'model' => config('services.ollama.embedding_model'),
                'input' => array_values($texts),
            ]);

        $response->throw();

        $embeddings = $response->json('embeddings');

        if (!is_array($embeddings) || count($embeddings) !== count($texts)) {
            throw new RuntimeException(
                'O Ollama retornou uma quantidade inválida de embeddings.'
            );
        }

        foreach ($embeddings as $embedding) {
            if (
                !is_array($embedding)
                || count($embedding) !== 1024
            ) {
                throw new RuntimeException(
                    'O Ollama retornou um embedding com dimensões inválidas.'
                );
            }
        }

        return $embeddings;
    }
}
