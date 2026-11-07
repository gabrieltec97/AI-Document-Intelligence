<?php

namespace App\Services\Chunking;

class ChunkingService
{
    private const CHUNK_SIZE = 1500;

    private const CHUNK_OVERLAP = 200;

    /**
     * Divide um texto em blocos com sobreposição.
     *
     * @return array<int, string>
     */
    public function split(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        $length = mb_strlen($text, 'UTF-8');

        if ($length <= self::CHUNK_SIZE) {
            return [$text];
        }

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $remaining = $length - $start;

            $chunkLength = min(self::CHUNK_SIZE, $remaining);

            $chunk = mb_substr(
                $text,
                $start,
                $chunkLength,
                'UTF-8'
            );

            // Preserva palavras sempre que possível.
            if ($remaining > self::CHUNK_SIZE) {
                $lastSpace = mb_strrpos(
                    $chunk,
                    ' ',
                    0,
                    'UTF-8'
                );

                if (
                    $lastSpace !== false
                    && $lastSpace >= (int) ($chunkLength * 0.7)
                ) {
                    $chunk = mb_substr(
                        $chunk,
                        0,
                        $lastSpace,
                        'UTF-8'
                    );

                    $chunkLength = mb_strlen($chunk, 'UTF-8');
                }
            }

            $chunk = trim($chunk);

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            // Interrompe quando o conteúdo restante já foi incluído.
            if ($start + $remaining <= $length &&
                $remaining <= self::CHUNK_SIZE) {
                break;
            }

            $start += $chunkLength - self::CHUNK_OVERLAP;
        }

        return $chunks;
    }
}
