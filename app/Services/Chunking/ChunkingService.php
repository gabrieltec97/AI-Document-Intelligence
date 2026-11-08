<?php

namespace App\Services\Chunking;

class ChunkingService
{
    private const CHUNK_SIZE = 1500;

    private const CHUNK_OVERLAP = 200;

    /**
     * Divide um texto em blocos preservando as seções do documento.
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

        $sections = $this->splitIntoSections($text);

        $chunks = [];

        foreach ($sections as $section) {
            $sectionChunks = $this->splitIntoChunks($section);

            foreach ($sectionChunks as $chunk) {
                if ($chunk !== '') {
                    $chunks[] = $chunk;
                }
            }
        }

        return $chunks;
    }

    /**
     * Divide o texto e identifica os metadados de cada bloco.
     *
     * @return array<int, array{
     *     content: string,
     *     section: string,
     *     company: ?string,
     *     position: ?string
     * }>
     */
    public function splitWithMetadata(string $text): array
    {
        $chunks = $this->split($text);

        $result = [];

        $currentSection = 'general';
        $currentCompany = null;
        $currentPosition = null;

        foreach ($chunks as $chunk) {
            $firstLine = trim(explode("\n", $chunk)[0]);

            $detectedSection = $this->detectSection($firstLine);

            if ($detectedSection !== null) {
                $currentSection = $detectedSection;

                $currentCompany = null;
                $currentPosition = null;
            }

            if ($currentSection === 'professional_experience') {
                $experience = $this->extractExperienceMetadata($firstLine);

                if ($experience !== null) {
                    $currentPosition = $experience['position'];
                    $currentCompany = $experience['company'];
                }
            }

            $result[] = [
                'content' => $chunk,
                'section' => $currentSection,
                'company' => $currentCompany,
                'position' => $currentPosition,
            ];
        }

        return $result;
    }

    /**
     * Identifica a seção pelo título do bloco.
     */
    private function detectSection(string $line): ?string
    {
        $patterns = [
            'objective' => '/^OBJETIVO PROFISSIONAL$/iu',
            'technical_skills' => '/^HABILIDADES TÉCNICAS\b.*$/iu',
            'professional_experience' => '/^EXPERI[ÊE]NCIA PROFISSIONAL$/iu',
            'projects' => '/^PROJETOS PESSOAIS\b.*$/iu',
            'education' => '/^FORMAÇÃO ACADÊMICA$/iu',
        ];

        foreach ($patterns as $section => $pattern) {
            if (preg_match($pattern, $line)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Extrai cargo e empresa de uma linha de experiência profissional.
     *
     * Exemplo:
     * Analista de Sistemas | Cet Logistics 2025 — 2026
     *
     * @return array{position: string, company: string}|null
     */
    private function extractExperienceMetadata(string $line): ?array
    {
        if (!str_contains($line, '|')) {
            return null;
        }

        [$position, $company] = explode('|', $line, 2);

        $position = trim($position);
        $company = trim($company);

        $company = preg_replace(
            '/\s+(?:19|20)\d{2}\s*[—–-]\s*(?:(?:19|20)\d{2}|atual|presente)\s*$/iu',
            '',
            $company
        ) ?? $company;

        if ($position === '' || $company === '') {
            return null;
        }

        return [
            'position' => $position,
            'company' => $company,
        ];
    }

    /**
     * Separa documentos por títulos de seção e experiências profissionais.
     *
     * @return array<int, string>
     */
    private function splitIntoSections(string $text): array
    {
        $sectionPattern = '/^(OBJETIVO PROFISSIONAL|HABILIDADES TÉCNICAS.*|EXPERI[ÊE]NCIA PROFISSIONAL|PROJETOS PESSOAIS.*|FORMAÇÃO ACADÊMICA)\s*$/imu';

        $experienceHeaderPattern = '/\|.*\b(?:19|20)\d{2}\s*[—–-]\s*(?:(?:19|20)\d{2}|atual|presente)\b/iu';

        $lines = preg_split('/\R/u', $text) ?: [];

        $sections = [];
        $currentSection = [];
        $inProfessionalExperience = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match($sectionPattern, $line)) {
                if ($currentSection !== []) {
                    $sections[] = trim(implode("\n", $currentSection));
                }

                $currentSection = [$line];

                $inProfessionalExperience = preg_match(
                        '/^EXPERI[ÊE]NCIA PROFISSIONAL$/iu',
                        $line
                    ) === 1;

                continue;
            }

            if (
                $inProfessionalExperience
                && preg_match($experienceHeaderPattern, $line)
            ) {
                if ($currentSection !== []) {
                    $sections[] = trim(implode("\n", $currentSection));
                }

                $currentSection = [$line];

                continue;
            }

            if ($line !== '') {
                $currentSection[] = $line;
            }
        }

        if ($currentSection !== []) {
            $sections[] = trim(implode("\n", $currentSection));
        }

        return $sections !== [] ? $sections : [$text];
    }

    /**
     * Divide uma seção em blocos respeitando o tamanho máximo.
     *
     * @return array<int, string>
     */
    private function splitIntoChunks(string $text): array
    {
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

            if ($remaining > self::CHUNK_SIZE) {
                $lastSpace = mb_strrpos($chunk, ' ', 0, 'UTF-8');

                if (
                    $lastSpace !== false
                    && $lastSpace >= (int) ($chunkLength * 0.7)
                ) {
                    $chunk = mb_substr($chunk, 0, $lastSpace, 'UTF-8');
                    $chunkLength = mb_strlen($chunk, 'UTF-8');
                }
            }

            $chunk = trim($chunk);

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            if ($remaining <= self::CHUNK_SIZE) {
                break;
            }

            $start += $chunkLength - self::CHUNK_OVERLAP;
        }

        return $chunks;
    }
}
