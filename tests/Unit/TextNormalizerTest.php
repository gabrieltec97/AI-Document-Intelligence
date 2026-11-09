<?php

namespace Tests\Unit;

use App\Services\Text\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    private TextNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new TextNormalizer();
    }

    public function test_it_returns_empty_string_for_empty_text(): void
    {
        $this->assertSame('', $this->normalizer->normalize(''));
    }

    public function test_it_reduces_excessive_spaces(): void
    {
        $text = "Este   é    um texto\t\tcom espaços.";

        $this->assertSame(
            'Este é um texto com espaços.',
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_joins_lines_that_continue_the_same_sentence(): void
    {
        $text = "Os pronomes relativos são utilizados para unir\norações e evitar a repetição de termos\njá mencionados.";

        $expected = 'Os pronomes relativos são utilizados para unir orações e evitar a repetição de termos já mencionados.';

        $this->assertSame(
            $expected,
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_paragraph_separation(): void
    {
        $text = "Primeiro parágrafo com informações.\n\nSegundo parágrafo com outras informações.";

        $this->assertSame(
            "Primeiro parágrafo com informações.\n\nSegundo parágrafo com outras informações.",
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_titles_on_separate_lines(): void
    {
        $text = "INTRODUÇÃO\n\nEste é o primeiro parágrafo do documento.";

        $this->assertSame(
            "INTRODUÇÃO\n\nEste é o primeiro parágrafo do documento.",
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_bulleted_lists(): void
    {
        $text = "Conteúdos:\n• Primeiro item\n• Segundo item\n• Terceiro item";

        $this->assertSame(
            $text,
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_numbered_lists(): void
    {
        $text = "Etapas:\n1. Primeira etapa\n2. Segunda etapa\n3. Terceira etapa";

        $this->assertSame(
            $text,
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_table_rows_and_columns(): void
    {
        $text = "Nome | Cargo\nGabriel | Desenvolvedor\nMaria | Analista";

        $this->assertSame(
            $text,
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_utf8_characters(): void
    {
        $text = "Informação\nsobre documentação\n e configuração.";

        $normalized = $this->normalizer->normalize($text);

        $this->assertTrue(mb_check_encoding($normalized, 'UTF-8'));
        $this->assertStringContainsString('Informação', $normalized);
        $this->assertStringContainsString('configuração', $normalized);
    }


    public function test_it_joins_sentences_without_merging_separate_paragraphs(): void
    {
        $text = "Os pronomes relativos são utilizados para unir orações.\nEsse recurso evita a repetição de termos.\n\nOutro parágrafo começa aqui.";

        $expected = "Os pronomes relativos são utilizados para unir orações. Esse recurso evita a repetição de termos.\n\nOutro parágrafo começa aqui.";

        $this->assertSame(
            $expected,
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_mixed_case_subtitles(): void
    {
        $text = "Pronomes Relativos\nSão utilizados para unir orações.";

        $this->assertSame(
            $text,
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_dash_list_items(): void
    {
        $text = "Características:\n- Primeiro item\n- Segundo item\n- Terceiro item";

        $this->assertSame(
            $text,
            $this->normalizer->normalize($text)
        );
    }


    public function test_it_preserves_short_section_titles(): void
    {
        $text = "Objetivo\nDescrever os objetivos do documento.";

        $this->assertSame(
            $text,
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_alternative_bullet_markers(): void
    {
        $text = "Conteúdo:\n○ Primeiro item\n> Segundo item\n▪ Terceiro item";

        $this->assertSame(
            $text,
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_reduces_excessive_line_breaks_without_merging_paragraphs(): void
    {
        $text = "Primeiro parágrafo.\n\n\n\nSegundo parágrafo.";

        $this->assertSame(
            "Primeiro parágrafo.\n\nSegundo parágrafo.",
            $this->normalizer->normalize($text)
        );
    }
    public function test_it_normalizes_grammar_study_material_without_losing_structure(): void
    {
        $text = "GRAMÁTICA\n\nPronomes Relativos\n\nOs pronomes relativos são utilizados para unir orações e evitar a repetição de termos\njá mencionados.\n\nPor exemplo:\n\nEu conheço o menino. O menino caiu no rio.\n\nEm vez de repetir a palavra menino, é possível unir as duas informações em uma única frase:\n\nEu conheço o menino que caiu no rio.\n\nPrincipais pronomes relativos:\n\n• que\n• quem\n• cujo\n• onde";

        $expected = "GRAMÁTICA\n\nPronomes Relativos\n\nOs pronomes relativos são utilizados para unir orações e evitar a repetição de termos já mencionados.\n\nPor exemplo:\n\nEu conheço o menino. O menino caiu no rio.\n\nEm vez de repetir a palavra menino, é possível unir as duas informações em uma única frase:\n\nEu conheço o menino que caiu no rio.\n\nPrincipais pronomes relativos:\n\n• que\n• quem\n• cujo\n• onde";

        $this->assertSame(
            $expected,
            $this->normalizer->normalize($text)
        );
    }

    public function test_it_preserves_column_spacing_in_plain_text_tables(): void
    {
        $text = "Nome          Cargo             Departamento\n"
            ."Gabriel       Desenvolvedor     Tecnologia\n"
            ."Maria         Analista          Financeiro";

        $normalized = $this->normalizer->normalize($text);

        $this->assertStringContainsString(
            "Nome          Cargo             Departamento",
            $normalized
        );

        $this->assertStringContainsString(
            "Gabriel       Desenvolvedor     Tecnologia",
            $normalized
        );
    }

    public function test_it_preserves_resume_layout_and_contact_information(): void
    {
        $text = "Desenvolvedor de Software / Full Stack\n"
            ."Telefone: (21) 99746-7377   E-mail: gabrielphp20@gmail.com"
            ."    GitHub: github.com/gabrieltec97"
            ."    LinkedIn: linkedin.com/in/\n"
            ."gabrieltec\n\n"
            ."HABILIDADES TÉCNICAS & FERRAMENTAS\n\n"
            ."Linguagens & Front- PHP, JavaScript (ES6+), HTML5, CSS3.\n"
            ."End:\n"
            ."Back-End &            Laravel, APIs RESTful e Python.\n"
            ."Frameworks:";

        $normalized = $this->normalizer->normalize($text);

        $this->assertStringContainsString(
            "Desenvolvedor de Software / Full Stack\nTelefone:",
            $normalized
        );

        $this->assertStringContainsString(
            "Telefone: (21) 99746-7377   E-mail:",
            $normalized
        );

        $this->assertStringContainsString(
            "Back-End &            Laravel, APIs RESTful e Python.\nFrameworks:",
            $normalized
        );
    }
}
