<?php

namespace App\Services\Rag;

use App\Models\User;
use App\Services\Generation\GenerationService;
use App\Services\Search\SemanticSearchService;
use Illuminate\Support\Collection;

class RagService
{
    public function __construct(
        private SemanticSearchService $searchService,
        private GenerationService $generationService
    ) {}

    /**
     * Responde uma pergunta utilizando os documentos do usuário.
     */
    public function ask(
        User $user,
        string $question,
        int $limit = 5
    ): array {
        $chunks = $this->searchService->search(
            $user,
            $question,
            $limit
        );

        if ($chunks->isEmpty()) {
            return [
                'answer' => 'Não encontrei conteúdo nos seus documentos processados para responder a essa pergunta.',
                'sources' => [],
                'citation_validation' => [
                    'valid' => true,
                    'has_citations' => false,
                    'used' => [],
                    'invalid' => [],
                    'uncited_items' => [],
                    'abstained' => true,
                ],
            ];
        }

        $context = [];

        foreach ($chunks as $index => $chunk) {
            $sourceNumber = $index + 1;

            $section = match ($chunk->section) {
                'professional_experience' => 'Experiência profissional',
                'technical_skills' => 'Habilidades técnicas',
                'objective' => 'Objetivo profissional',
                'projects' => 'Projetos pessoais',
                'education' => 'Formação acadêmica',
                default => 'Seção geral',
            };

            $metadata = [
                "Seção: {$section}",
            ];

            if ($chunk->company) {
                $metadata[] = "Empresa: {$chunk->company}";
            }

            if ($chunk->position) {
                $metadata[] = "Cargo: {$chunk->position}";
            }

            $context[] = sprintf(
                "[%d]\nDocumento: %s\n%s\n\nConteúdo:\n%s",
                $sourceNumber,
                $chunk->document->original_name,
                implode("\n", $metadata),
                $chunk->content
            );
        }

        // Instruções adicionais somente quando a pergunta solicitar classificação.
        $classificationInstructions = '';

        if (preg_match(
            '/\b(classifique|classificar|classificação|categorize|categorizar|categorias)\b/ui',
            $question
        )) {
            $classificationInstructions = implode("\n", [
                'CLASSIFICAÇÃO TÉCNICA:',
                'Classifique somente os itens que aparecem explicitamente no contexto.',
                'Não acrescente tecnologias, ferramentas ou metodologias que não estejam documentadas.',
                'Utilize categorias tecnicamente adequadas, sem alterar o conteúdo das fontes.',
                'Não classifique HTML ou CSS como linguagens de programação.',
                'Não classifique Scrum ou Kanban como linguagens, frameworks ou ferramentas.',
                'Não classifique Code Review como linguagem ou ferramenta.',
                'Não classifique idiomas como tecnologias de desenvolvimento.',
                'Cada item deve aparecer uma única vez, na categoria mais adequada.',
                '',
            ]);
        }

        $prompt = implode("\n", [
            'Você é um assistente especializado em extração e análise de informações documentais.',
            'Responda sempre em português brasileiro.',
            '',
            'REGRAS FUNDAMENTAIS:',
            'Utilize exclusivamente as informações presentes nas fontes fornecidas.',
            'Não invente, deduza, suponha ou complete informações com conhecimentos externos.',
            'Não utilize conhecimentos gerais sobre empresas, cargos ou tecnologias para preencher lacunas.',
            'Não crie empresas, cargos, datas, tecnologias, atividades ou experiências.',
            'Quando o contexto não contiver a informação solicitada, informe que ela não foi encontrada.',
            'A ausência de uma informação no contexto não autoriza inferências.',
            '',
            'EXTRAÇÃO FIEL:',
            'Extraia informações de forma fiel ao conteúdo das fontes.',
            'Não transforme uma tecnologia em outra por associação ou conhecimento técnico.',
            'Não considere uma tecnologia comprovada apenas porque ela costuma ser utilizada junto com outra.',
            'Não infira linguagens, frameworks, bibliotecas, bancos de dados ou ferramentas a partir de atividades descritas.',
            'Se uma fonte mencionar PHP, isso não comprova o uso de Laravel.',
            'Se uma fonte mencionar desenvolvimento web, isso não comprova o uso de JavaScript, HTML, CSS ou qualquer framework.',
            'Se uma fonte mencionar APIs RESTful, isso não comprova o uso de uma linguagem ou ferramenta específica além das explicitamente citadas.',
            'Não amplie listas de tecnologias com itens que não estejam escritos nas fontes.',
            '',
            'INTERPRETAÇÃO DAS FONTES:',
            'Cada fonte possui um número, um documento, uma seção e pode possuir empresa e cargo.',
            'Utilize os metadados para identificar corretamente a origem das informações.',
            'Habilidades técnicas representam conhecimentos gerais declarados no documento.',
            'Experiência profissional representa atividades e tecnologias associadas àquela experiência.',
            'Nunca atribua habilidades gerais a uma empresa sem evidência explícita na experiência correspondente.',
            'Nunca misture informações de empresas diferentes.',
            'Não confunda projetos pessoais com experiências profissionais.',
            '',
            'PERGUNTAS SOBRE EMPRESAS:',
            'Quando a pergunta mencionar uma empresa específica, utilize somente fontes de experiência profissional associadas a ela.',
            'Não utilize habilidades gerais para completar informações ausentes da experiência profissional.',
            'Informe atividades e tecnologias somente quando estiverem explicitamente documentadas na experiência correspondente.',
            'Se a tecnologia não estiver mencionada na experiência da empresa, não a inclua na resposta.',
            'Se não houver evidência suficiente, informe essa limitação em vez de tentar completar a resposta.',
            '',
            'COMPARAÇÕES:',
            'Não invente informações nem estabeleça comparações que não sejam sustentadas pelo contexto.',
            'Compare somente empresas, atividades, tecnologias e cargos explicitamente descritos nas fontes recuperadas.',
            'Não conclua que uma experiência foi melhor, mais complexa ou mais avançada que outra sem evidências explícitas.',
            'Quando não houver informações suficientes para comparar dois itens, informe essa limitação.',
            'Não atribua atividades comuns a uma empresa se elas estiverem documentadas somente na experiência de outra empresa.',
            '',
            $classificationInstructions,
            'PRECISÃO E FONTES:',
            'Toda afirmação factual deve apresentar a referência [n] da fonte que a comprova.',
            'Utilize somente os números de fontes existentes no contexto.',
            'Coloque a referência imediatamente após a afirmação correspondente.',
            'Quando uma afirmação depender de mais de uma fonte, cite todas as fontes relevantes.',
            'Não atribua uma referência a uma informação que ela não comprova.',
            'Não repita informações presentes em mais de um trecho.',
            '',
            'FORMATO DA RESPOSTA:',
            'Responda diretamente ao que foi perguntado.',
            'Organize a resposta em tópicos quando isso facilitar a leitura.',
            'Seja objetivo e evite informações irrelevantes.',
            'Não liste itens ausentes dos documentos.',
            'Não apresente suposições como fatos.',
            '',
            'CONTEXTO DOS DOCUMENTOS:',
            implode("\n\n", $context),
            '',
            'PERGUNTA DO USUÁRIO:',
            $question,
            '',
            'RESPOSTA:',
        ]);

        if ($this->isProfessionalComparison($question, $chunks)) {
            $answer = $this->buildProfessionalComparison($chunks);

            $citationValidation = $this->validateCitations(
                $answer,
                $chunks->count()
            );
        } else {
            $answer = $this->generationService->generate($prompt);

            $citationValidation = $this->validateCitations(
                $answer,
                $chunks->count()
            );

            if (
                ! $citationValidation['has_citations']
                && $chunks->count() === 1
            ) {
                $answer = $this->addSingleSourceCitation($answer);

                $citationValidation = $this->validateCitations(
                    $answer,
                    $chunks->count()
                );
            }
        }

        if (
            ! $citationValidation['has_citations']
            && $this->isExplicitAbstention($answer)
        ) {
            $citationValidation['valid'] = true;
            $citationValidation['abstained'] = true;
        }

        $sources = $chunks->map(function ($chunk) {
            return [
                'document_id' => $chunk->document_id,
                'document_name' => $chunk->document->original_name,
                'chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'similarity' => (float) $chunk->similarity,
            ];
        })->all();

        return [
            'answer' => $answer,
            'sources' => $sources,
            'citation_validation' => $citationValidation,
        ];
    }

    /**
     * Identifica perguntas comparativas entre experiências profissionais.
     */
    private function isProfessionalComparison(
        string $question,
        Collection $chunks
    ): bool {
        $isComparison = preg_match(
            '/\b(compare|comparar|comparação|comparativo|comparativa)\b/ui',
            $question
        );

        if (! $isComparison) {
            return false;
        }

        $companies = $chunks
            ->filter(
                fn ($chunk) => $chunk->section === 'professional_experience'
                    && ! empty($chunk->company)
            )
            ->pluck('company')
            ->unique()
            ->values();

        return $companies->count() >= 2;
    }

    private function buildProfessionalComparison(
        Collection $chunks
    ): string {
        $groups = [];

        foreach ($chunks as $index => $chunk) {
            if (
                $chunk->section !== 'professional_experience'
                || empty($chunk->company)
            ) {
                continue;
            }

            $groups[$chunk->company][] = [
                'chunk' => $chunk,
                'source_number' => $index + 1,
            ];
        }

        $sections = [];

        foreach ($groups as $company => $experiences) {
            $section = "### {$company}";

            foreach ($experiences as $experience) {
                $chunk = $experience['chunk'];
                $sourceNumber = $experience['source_number'];

                if (! empty($chunk->position)) {
                    $section .= "\n\n**Cargo:** {$chunk->position} [{$sourceNumber}]";
                }

                $content = trim($chunk->content);
                $lines = preg_split('/\r\n|\r|\n/', $content);

                $items = [];
                $currentItem = null;
                $currentIsBullet = false;

                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '') {
                        if ($currentItem !== null) {
                            $items[] = [
                                'content' => $currentItem,
                                'is_bullet' => $currentIsBullet,
                            ];

                            $currentItem = null;
                            $currentIsBullet = false;
                        }

                        continue;
                    }

                    if (preg_match('/^[-*+]\s+(.+)$/u', $line, $matches)) {
                        if ($currentItem !== null) {
                            $items[] = [
                                'content' => $currentItem,
                                'is_bullet' => $currentIsBullet,
                            ];
                        }

                        $currentItem = trim($matches[1]);
                        $currentIsBullet = true;

                        continue;
                    }

                    if ($currentItem === null) {
                        $currentItem = $line;
                        $currentIsBullet = false;
                    } else {
                        $currentItem .= ' '.$line;
                    }
                }

                if ($currentItem !== null) {
                    $items[] = [
                        'content' => $currentItem,
                        'is_bullet' => $currentIsBullet,
                    ];
                }

                foreach ($items as $item) {
                    $itemContent = preg_replace(
                        '/\s+/',
                        ' ',
                        trim($item['content'])
                    );

                    if ($itemContent === '') {
                        continue;
                    }

                    $prefix = $item['is_bullet'] ? '- ' : '';

                    $section .= "\n{$prefix}{$itemContent} [{$sourceNumber}]";
                }
            }

            $sections[] = $section;
        }

        return implode("\n\n", $sections);
    }

    /**
     * Adiciona uma referência quando existe somente uma fonte disponível.
     */
    private function addSingleSourceCitation(string $answer): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $answer);

        $result = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if (
                $trimmedLine !== ''
                && preg_match('/^([-*+]|\d+[.)])\s+/', $trimmedLine)
                && ! preg_match('/\[\d+\]/', $trimmedLine)
            ) {
                $line .= ' [1]';
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }

    /**
     * Identifica respostas explícitas de ausência de informação documental.
     */
    private function isExplicitAbstention(string $answer): bool
    {
        return preg_match(
            '/^\s*não\s+(?:(?:foram\s+encontrad[oa]s?|encontrei)\s+|há\s+)(?:nenhuma\s+)?(?:informações?|registros?|dados?|referências?)\b.*\b(?:currículo|documento|arquivo|fontes?|contexto)\b.*[.!]?\s*$/isu',
            trim($answer)
        ) === 1;
    }

    private function validateCitations(
        string $answer,
        int $sourceCount
    ): array {
        preg_match_all('/\[(\d+)\]/', $answer, $matches);

        $used = array_values(array_unique(
            array_map('intval', $matches[1])
        ));

        $invalid = array_values(array_filter(
            $used,
            fn (int $number): bool => $number < 1 || $number > $sourceCount
        ));

        $uncitedItems = [];

        $lines = preg_split('/\r\n|\r|\n/', $answer);

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            $isListItem = preg_match(
                '/^([-*+]|\d+[.)])\s+/',
                $trimmedLine
            );

            $hasCitation = preg_match('/\[\d+\]/', $trimmedLine);

            if ($isListItem && ! $hasCitation) {
                $uncitedItems[] = $trimmedLine;
            }
        }

        $hasCitations = ! empty($used);

        return [
            'valid' => $hasCitations
                && empty($invalid)
                && empty($uncitedItems),
            'has_citations' => $hasCitations,
            'used' => $used,
            'invalid' => $invalid,
            'uncited_items' => $uncitedItems,
            'abstained' => false,
        ];
    }
}
