<?php

namespace App\Services\Embeddings;

interface EmbeddingService
{
    /**
     * Gera embeddings para uma lista de textos.
     *
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array;
}
