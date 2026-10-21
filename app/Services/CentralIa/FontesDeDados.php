<?php

namespace App\Services\CentralIa;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Dados da pessoa que vêm de uma fonte OFICIAL, e não de onde cada agente
 * achar.
 *
 * Pedido do dono em 10/10/2026: "me faça um resumo da agenda de hoje" não
 * pode trazer uma resposta por IA. Trazia porque cada uma procurava a agenda
 * num lugar — um arquivo antigo em ~/projetos/.agenda, a Agenda do
 * AlfaMatriz — e o Codex, isolado, nem alcança a ponte do Google Agenda. Aqui
 * se decide QUAL fonte e QUAL período; o runner lê a fonte do mesmo jeito
 * para qualquer agente e o prompt manda usar só aquilo.
 */
class FontesDeDados
{
    private const AGENDA = ['agenda', 'compromisso', 'compromissos', 'reunião', 'reuniao', 'reuniões', 'reunioes', 'evento', 'eventos', 'calendário', 'calendario'];

    /**
     * Relatório de dados do negócio sem período: pergunta antes de gastar IA
     * (pedido do dono ao Codex, 11/10/2026 — "nunca invente números").
     *
     * Só para ANÁLISE de dados financeiros. Agenda fica de fora: "a agenda"
     * sem data é a de hoje (dono, 10/10/2026). E pedido de código ou de tela
     * que só cita o assunto ("corrija o lembrete de contas a pagar", "quero
     * receber aviso") não é relatório — era recusado com "Qual período?".
     */
    public function exigirPeriodo(string $texto, string $modo = 'analisar'): void
    {
        if ($modo === 'implementar') return;
        $t = Str::lower(Str::ascii($texto));
        $dados = preg_match('/\b(?:financeiro|financeira|contas a (?:pagar|receber)|a pagar|a receber|recebido|pago|receitas|despesas|faturamento|fluxo de caixa|balanco|inadimplencia|saldo)\b/', $t);
        $relatorio = preg_match('/\b(?:relatorio|resumo|resuma|analise|analisar|balanco|levantamento|quanto|quantos|quantas|total|totais|panorama|situacao)\b/', $t);
        $codigo = preg_match('/\b(?:codigo|backend|frontend|controller|migration|migracao|bug|refator\w*|tela|botao|campo|filtro|rota|teste|testes|funcao|metodo|classe|lembrete|aviso|notificacao|implement\w*|corrig\w*|ajust\w*|crie|criar|adicion\w*)\b/', $t);
        $periodo = preg_match('/\b(?:hoje|ontem|amanha|semana|mes|ano|diario|mensal|anual|desde|ate|periodo todo|todos os periodos|trimestre|semestre)\b|\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b|\b\d{4}-\d{2}-\d{2}\b|\b\d+ dias\b|\b(?:janeiro|fevereiro|marco|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b/', $t);
        if ($dados && $relatorio && ! $codigo && ! $periodo) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'instrucao' => 'Qual período você deseja analisar? Informe as datas inicial e final ou um período como esta semana. Nenhuma IA foi acionada.',
            ]);
        }
    }

    /**
     * @return list<array{tipo: string, inicio: string, dias: int, rotulo: string}>
     */
    public function detectar(string $texto): array
    {
        $t = Str::lower(Str::squish($texto));
        $fontes = [];
        // Pedido de CRIAR ou mudar compromisso não é consulta: vai para a IA, que marca pelo quadro.
        // Em 21/10/2026 "crie uma agenda para amanhã às 10:00 para o Alexandre" voltou como a
        // lista da agenda pessoal, "sem IA", e nada foi marcado.
        if (preg_match('/(^|[^\pL])(crie|criar|cria|marque|marcar|marca|agende|agendar|cadastre|cadastrar|registre|registrar|lance|lancar|lançar|coloque|colocar|inclua|incluir|adicione|adicionar|remarque|remarcar|desmarque|desmarcar|cancele|cancelar|mude|mudar|altere|alterar)($|[^\pL])/u', $t)) {
            return [];
        }

        if (collect(self::AGENDA)->contains(fn ($p) => preg_match('/(^|[^\pL])'.preg_quote($p, '/').'($|[^\pL])/u', $t))) {
            $fontes[] = ['tipo' => 'agenda'] + $this->periodo($t);
        }

        return $fontes;
    }

    /**
     * Hoje, se nada disser o contrário — "a agenda" sem data é a de hoje.
     */
    private function periodo(string $t): array
    {
        $hoje = Carbon::today(config('app.timezone'));
        $tem = fn (string $re) => (bool) preg_match($re, $t);

        return match (true) {
            $tem('/depois de amanh[ãa]/u') => ['inicio' => $hoje->copy()->addDays(2)->toDateString(), 'dias' => 1, 'rotulo' => 'depois de amanhã'],
            $tem('/amanh[ãa]/u') => ['inicio' => $hoje->copy()->addDay()->toDateString(), 'dias' => 1, 'rotulo' => 'amanhã'],
            $tem('/pr[óo]xim[ao]s? semana/u') => ['inicio' => $hoje->copy()->next(Carbon::MONDAY)->toDateString(), 'dias' => 7, 'rotulo' => 'próxima semana'],
            $tem('/(semana|7 dias|sete dias)/u') => ['inicio' => $hoje->toDateString(), 'dias' => 7, 'rotulo' => 'próximos 7 dias'],
            $tem('/(m[êe]s|30 dias)/u') => ['inicio' => $hoje->toDateString(), 'dias' => 30, 'rotulo' => 'próximos 30 dias'],
            default => ['inicio' => $hoje->toDateString(), 'dias' => 1, 'rotulo' => 'hoje'],
        };
    }
}
