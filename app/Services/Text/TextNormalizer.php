<?php

namespace App\Services\Text;

class TextNormalizer
{
    /**
     * Normaliza o texto sem remover informações ou destruir estruturas.
     */
    public function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if (trim($text) === '') {
            return '';
        }

        $lines = explode("\n", $text);

        $normalizedLines = [];

        foreach ($lines as $line) {
            $line = preg_replace('/[ \t]+/u', ' ', $line) ?? $line;

            $normalizedLines[] = trim($line);
        }

        $normalizedText = implode("\n", $normalizedLines);

        // Preserva parágrafos, limitando espaços verticais excessivos.
        $normalizedText = preg_replace(
            '/\n{3,}/u',
            "\n\n",
            $normalizedText
        ) ?? $normalizedText;

        $lines = explode("\n", $normalizedText);

        $result = [];

        foreach ($lines as $line) {
            if ($result === []) {
                $result[] = $line;
                continue;
            }

            $previousIndex = count($result) - 1;
            $previousLine = $result[$previousIndex];

            if (
                $line !== ''
                && $previousLine !== ''
                && $this->shouldJoinLines($previousLine, $line)
            ) {
                $result[$previousIndex] .= ' '.$line;

                continue;
            }

            $result[] = $line;
        }

        return trim(implode("\n", $result));
    }

    /**
     * Decide se duas linhas pertencem à mesma frase.
     */
    private function shouldJoinLines(
        string $previousLine,
        string $currentLine
    ): bool {
        if (
            $this->isStructuralLine($previousLine)
            || $this->isStructuralLine($currentLine)
        ) {
            return false;
        }

        // Linhas de tabelas devem permanecer separadas.
        if (
            str_contains($previousLine, '|')
            || str_contains($currentLine, '|')
        ) {
            return false;
        }

        return true;
    }


    /**
     * Identifica linhas cuja estrutura deve ser preservada.
     */

    private function isStructuralLine(string $line): bool
    {
        $line = trim($line);

        if ($line === '') {
            return true;
        }

        // Títulos inteiramente em caixa alta.
        if (
            mb_strtoupper($line, 'UTF-8') === $line
            && preg_match('/\p{L}/u', $line)
        ) {
            return true;
        }

        // Títulos curtos conhecidos em materiais e documentos.
        if (preg_match(
            '/^(?:Objetivo|Introdução|Conclusão|Desenvolvimento|'
            .'Referências|Sumário|Justificativa|Metodologia|'
            .'Resultados|Resumo|Gabarito|Exercícios|'
            .'Bibliografia|Anexos|Apêndice)$/iu',
            $line
        )) {
            return true;
        }

        // Subtítulos em formato Title Case.
        if (
            preg_match(
                '/^\p{Lu}[\p{L}\p{M}\'’-]*(?:\s+\p{Lu}[\p{L}\p{M}\'’-]*)+$/u',
                $line
            )
        ) {
            return true;
        }

        // Listas com marcadores variados.
        if (preg_match('/^(?:[•●▪◦‣○*>-])\s+/u', $line)) {
            return true;
        }

        // Listas numeradas ou alfabéticas.
        if (preg_match('/^(?:\d+[.)]|[a-zA-Z][.)])\s+/u', $line)) {
            return true;
        }

        return false;
    }

}
