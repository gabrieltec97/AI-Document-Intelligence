<?php

namespace App\Services\Search;

use InvalidArgumentException;

class CosineSimilarity
{
    /**
     * Calcula a similaridade de cosseno entre dois vetores.
     */
    public function calculate(array $vectorA, array $vectorB): float
    {
        if (count($vectorA) !== count($vectorB)) {
            throw new InvalidArgumentException(
                'Os vetores precisam ter as mesmas dimensões.'
            );
        }

        if ($vectorA === [] || $vectorB === []) {
            throw new InvalidArgumentException(
                'Os vetores não podem estar vazios.'
            );
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($vectorA as $index => $valueA) {
            $valueB = $vectorB[$index];

            $dotProduct += $valueA * $valueB;
            $normA += $valueA ** 2;
            $normB += $valueB ** 2;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}
