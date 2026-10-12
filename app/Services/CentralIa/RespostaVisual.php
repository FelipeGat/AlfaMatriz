<?php

namespace App\Services\CentralIa;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/** Presentation only: never infers amounts or executes model-supplied code. */
final class RespostaVisual
{
    public static function apresentar(string $original): array
    {
        $original = ProtecaoDeSegredos::texto($original);
        $texto = $original;
        $relatorio = null;
        if (preg_match('/\[CENTRAL_RELATORIO_V1\]\s*(.*?)\s*\[\/CENTRAL_RELATORIO_V1\]/s', $original, $m)) {
            $dados = json_decode($m[1], true);
            if (self::valido($dados)) {
                $relatorio = $dados;
                $texto = trim(str_replace($m[0], '', $original));
            }
        }
        $env = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
        $env->addExtension(new CommonMarkCoreExtension);
        $env->addExtension(new TableExtension);
        $converter = new MarkdownConverter($env);
        $longo = mb_strlen($texto) > 1400;
        $resumo = $longo ? mb_substr($texto, 0, 600)."\n\n…" : $texto;
        return ['html' => (string) $converter->convert($texto), 'resumo_html' => (string) $converter->convert($resumo), 'longo' => $longo, 'relatorio' => $relatorio];
    }

    public static function valido(mixed $d): bool
    {
        if (!is_array($d) || ($d['versao'] ?? null) !== 1 || !self::texto($d['titulo'] ?? null, 120)
            || !self::texto($d['periodo'] ?? null, 160) || !self::texto($d['fonte'] ?? null, 500)
            || !is_array($d['metricas'] ?? null) || !array_is_list($d['metricas']) || count($d['metricas']) > 8) {
            return false;
        }
        foreach ($d['metricas'] as $m) {
            if (!is_array($m) || !self::texto($m['rotulo'] ?? null, 80)
                || !is_int($m['centavos'] ?? null) || abs($m['centavos']) > 1000000000000
                || !self::texto($m['criterio'] ?? null, 200)) {
                return false;
            }
        }
        foreach (['alertas', 'acoes'] as $campo) {
            if (isset($d[$campo])) {
                if (!is_array($d[$campo]) || !array_is_list($d[$campo]) || count($d[$campo]) > 6) return false;
                foreach ($d[$campo] as $v) if (!self::texto($v, 300)) return false;
            }
        }
        if (isset($d['contagens'])) {
            if (!is_array($d['contagens']) || !array_is_list($d['contagens']) || count($d['contagens']) > 6) return false;
            foreach ($d['contagens'] as $v) {
                if (!is_array($v) || !self::texto($v['rotulo'] ?? null,80) || !is_int($v['quantidade'] ?? null) || $v['quantidade'] < 0) return false;
            }
        }
        if (isset($d['grafico'])) {
            $g = $d['grafico'];
            if (!is_array($g) || !self::texto($g['titulo'] ?? null, 120)
                || !in_array($g['tipo'] ?? null, ['barras', 'linha'], true)
                || !is_array($g['pontos'] ?? null) || !array_is_list($g['pontos'])
                || count($g['pontos']) < 2 || count($g['pontos']) > 24) return false;
            foreach ($g['pontos'] as $p) {
                if (!is_array($p) || !self::texto($p['rotulo'] ?? null, 80)
                    || !is_int($p['centavos'] ?? null) || abs($p['centavos']) > 1000000000000) return false;
            }
        }
        if (isset($d['tabela'])) {
            if (!is_array($d['tabela']) || !array_is_list($d['tabela']) || count($d['tabela']) > 100) return false;
            foreach ($d['tabela'] as $p) {
                if (!is_array($p) || !self::texto($p['descricao'] ?? null, 200)
                    || !self::texto($p['data'] ?? null, 30) || !is_int($p['centavos'] ?? null)
                    || abs($p['centavos']) > 1000000000000
                    || (isset($p['estado']) && !in_array($p['estado'], ['aberto', 'vencido', 'pago', 'recebido'], true))) return false;
            }
        }
        // Unknown executable/chart specifications are never passed to the browser.
        return count(array_diff(array_keys($d), ['versao', 'titulo', 'periodo', 'fonte', 'metricas', 'grafico', 'tabela', 'alertas', 'acoes', 'contagens'])) === 0;
    }

    private static function texto(mixed $v, int $max): bool
    {
        return is_string($v) && trim($v) !== '' && mb_strlen($v) <= $max;
    }

    public static function telegram(string $original): string
    {
        $visual = self::apresentar($original);
        $r = $visual['relatorio'];
        if (!$r) {
            return ProtecaoDeSegredos::texto($original);
        }
        // A análise em texto vai junto: o celular recebia só os números, sem
        // a conclusão que explica o que fazer com eles.
        $analise = trim(preg_replace('/\[CENTRAL_RELATORIO_V1\].*?\[\/CENTRAL_RELATORIO_V1\]/s', '', ProtecaoDeSegredos::texto($original)));
        $linhas = array_values(array_filter([$analise, '', $r['titulo'], $r['periodo']], fn ($l) => $l !== null));
        foreach ($r['metricas'] as $m) {
            $linhas[] = $m['rotulo'].': R$ '.number_format($m['centavos'] / 100, 2, ',', '.').' — '.$m['criterio'];
        }
        foreach ($r['contagens'] ?? [] as $v) $linhas[] = $v['rotulo'].': '.$v['quantidade'];
        foreach ($r['alertas'] ?? [] as $v) $linhas[] = 'Atenção: '.$v;
        foreach ($r['acoes'] ?? [] as $v) $linhas[] = 'Próxima ação: '.$v;
        $linhas[] = 'Fonte: '.$r['fonte'];
        return implode("\n", $linhas);
    }

    public static function instrucao(): string
    {
        return 'Para uma apresentação de resultados, quando o nível de detalhe não foi escolhido e isso fizer diferença, pergunte uma única vez no dossiê: resposta Rápida ou Elaborada? Não pergunte em mensagens comuns ou se o pedido já indicar o formato. A escolha muda profundidade, nunca veracidade, permissões ou critérios; rápida não promete um tempo específico. Responda de forma executiva: conclusão primeiro, até três prioridades; detalhes técnicos separados no final. Nunca invente números, fontes ou consultas. Não leia nem reproduza arquivos de autenticação, chaves, scripts com senhas, tokens ou variáveis secretas; fontes financeiras devem usar uma ponte autorizada que retorne apenas os dados necessários. Para relatórios financeiros, se houver valores verificados, acrescente antes do dossiê [CENTRAL_RELATORIO_V1] JSON [/CENTRAL_RELATORIO_V1], com exatamente: versao:1, titulo:string, periodo:string explícito, fonte:string incluindo data da consulta, metricas:[{rotulo:string,centavos:integer,criterio:string}]. Máximo oito métricas. Opcional: contagens:[{rotulo:string,quantidade:integer}], alertas:[string], acoes:[string], até seis itens cada. Não confunda fluxo realizado, saldo previsto e lucro. Valores ausentes não são zero. Opcional: grafico:{titulo:string,tipo:"barras" ou "linha",pontos:[{rotulo:string,centavos:integer}]} para valores comparáveis com o mesmo critério; tabela:[{descricao:string,data:string,centavos:integer,estado:"aberto|vencido|pago|recebido" opcional}] para contas. Não inclua HTML ou código de gráficos. Se faltar informação material, use duvida e opcoes_duvida no dossiê e uma frase curta na resposta; não faça um relatório para perguntar.';
    }
}
