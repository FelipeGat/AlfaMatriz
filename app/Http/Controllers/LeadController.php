<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadAnexo;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        // Sem filtro de período por padrão: o quadro é a FILA de trabalho de
        // hoje, não um relatório de um recorte de tempo — um lead aberto há
        // seis meses ainda precisa aparecer. O filtro é para quem quer
        // examinar só o que ENTROU num período (ex.: leva da campanha de
        // julho), não para restringir o quadro do dia a dia.
        [$periodoDe, $periodoAte] = $this->periodoSelecionado($request);

        $leads = Lead::with(['revenda', 'sistema', 'vendedor', 'comentarios.autor', 'anexos.autor'])
            ->when(auth()->user()->temEscopoDeRevenda(), fn ($q) => $q->where('revenda_id', auth()->user()->revenda_id))
            ->when($periodoDe, fn ($q) => $q->whereDate('created_at', '>=', $periodoDe->toDateString()))
            ->when($periodoAte, fn ($q) => $q->whereDate('created_at', '<=', $periodoAte->toDateString()))
            ->orderByDesc('created_at')
            ->get();

        $colunas = collect(Lead::ESTAGIOS)->mapWithKeys(fn ($label, $key) => [
            $key => $leads->where('estagio', $key)->values(),
        ]);

        $abertos = $leads->whereNotIn('estagio', Lead::ESTAGIOS_TERMINAIS);
        $fechados = $leads->where('estagio', 'cliente_ativo');
        $perdidos = $leads->where('estagio', 'perdido');
        $total = $leads->count();

        $kpis = [
            'total' => $total,
            'abertos' => $abertos->count(),
            'fechados' => $fechados->count(),
            'perdidos' => $perdidos->count(),
            'taxa_conversao' => $total > 0 ? ($fechados->count() / $total) * 100 : 0,
            'pipeline_valor' => $abertos->sum('valor_estimado'),
            'ticket_medio' => $fechados->count() > 0 ? $fechados->sum('valor_estimado') / $fechados->count() : 0,
        ];

        $revendas = Revenda::where('ativo', true)
            ->when(auth()->user()->temEscopoDeRevenda(), fn ($q) => $q->where('id', auth()->user()->revenda_id))
            ->orderBy('nome')
            ->get();
        $sistemas = Sistema::produtos()->where('ativo', true)->orderBy('nome')->get();

        $estagios = $this->estagios($colunas);

        $filtroPeriodo = $this->filtroPeriodoSelecionado($request);

        return view('leads.index', compact('colunas', 'kpis', 'revendas', 'sistemas', 'estagios', 'filtroPeriodo') + [
            'periodoDe' => $periodoDe?->format('Y-m-d'),
            'periodoAte' => $periodoAte?->format('Y-m-d'),
        ]);
    }

    /**
     * As opções rápidas do filtro de período: mês anterior/atual/próximo
     * (relativos a HOJE, não ao lead) ou um intervalo personalizado. Sem
     * filtro reconhecido na URL, `todos` — o quadro mostra tudo, e as duas
     * datas voltam nulas para o `when()` de `index()` não restringir nada.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function periodoSelecionado(Request $request): array
    {
        return match ($this->filtroPeriodoSelecionado($request)) {
            'mes_anterior' => [now()->startOfMonth()->subMonth(), now()->startOfMonth()->subMonth()->endOfMonth()],
            'mes_atual' => [now()->startOfMonth(), now()->endOfMonth()],
            'proximo_mes' => [now()->startOfMonth()->addMonth(), now()->startOfMonth()->addMonth()->endOfMonth()],
            'personalizado' => $this->periodoPersonalizado($request),
            default => [null, null],
        };
    }

    private function filtroPeriodoSelecionado(Request $request): string
    {
        $valor = $request->query('periodo');

        return in_array($valor, ['mes_anterior', 'mes_atual', 'proximo_mes', 'personalizado'], true)
            ? $valor
            : 'todos';
    }

    /**
     * O intervalo do "Período personalizado" — `periodo_ate` antes de
     * `periodo_de` é trocado, pelo mesmo motivo do Painel Financeiro: quem
     * preenche duas datas fora de ordem não quer um erro, quer o intervalo
     * corrigido.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodoPersonalizado(Request $request): array
    {
        $de = $request->query('periodo_de');
        $ate = $request->query('periodo_ate');

        $inicio = $this->dataValida($de) ?? now()->startOfMonth();
        $fim = $this->dataValida($ate) ?? now();

        if ($fim->lessThan($inicio)) {
            [$inicio, $fim] = [$fim, $inicio];
        }

        return [$inicio->startOfDay(), $fim->endOfDay()];
    }

    private function dataValida(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $valor);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Metadados de cada coluna do quadro: a cor que marca o topo, quantos
     * leads há e quanto está em jogo ali.
     *
     * `exigeMotivo` marca a coluna que não aceita arrastar: mover para
     * "perdido" obriga a informar o motivo, e um card solto no lugar errado
     * não tem como perguntar. Nessa coluna o caminho é o menu do card.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection>  $colunas
     * @return array<int, array<string, mixed>>
     */
    private function estagios(\Illuminate\Support\Collection $colunas): array
    {
        $cores = [
            'lead' => 'accent',
            'qualificacao' => 'accent',
            'proposta' => 'brand',
            'contrato' => 'brand',
            'implantacao' => 'brand',
            'cliente_ativo' => 'good',
            'perdido' => 'crit',
        ];

        return collect(Lead::ESTAGIOS)->map(function ($label, $chave) use ($colunas, $cores) {
            $leads = $colunas[$chave];

            return [
                'chave' => $chave,
                'label' => $label,
                'cor' => $cores[$chave] ?? 'accent',
                'quantidade' => $leads->count(),
                'valor' => (float) $leads->sum('valor_estimado'),
                'exigeMotivo' => $chave === 'perdido',
            ];
        })->values()->all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'cpf_cnpj' => 'nullable|string|max:18',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:30',
            'revenda_id' => 'nullable|exists:revendas,id',
            'sistema_id' => 'nullable|exists:sistemas,id',
            'tipo_interesse' => 'required|in:saas,site,app,consultoria,marketing,outro',
            'origem' => 'nullable|string|max:255',
            'valor_estimado' => 'nullable|numeric|min:0',
            'observacoes' => 'nullable|string',
            'proximo_passo' => 'nullable|string',
        ]);

        $data['estagio'] = 'lead';
        $data['estagio_atualizado_em'] = now();
        $data['vendedor_id'] = auth()->id();

        if (auth()->user()->temEscopoDeRevenda()) {
            $data['revenda_id'] = auth()->user()->revenda_id;
        }

        Lead::create($data);

        return redirect()->route('leads.index')->with('status', 'Lead cadastrado.');
    }

    public function update(Request $request, Lead $lead)
    {
        $this->autorizarAcesso($lead);

        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'cpf_cnpj' => 'nullable|string|max:18',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:30',
            'revenda_id' => 'nullable|exists:revendas,id',
            'sistema_id' => 'nullable|exists:sistemas,id',
            'tipo_interesse' => 'required|in:saas,site,app,consultoria,marketing,outro',
            'origem' => 'nullable|string|max:255',
            'valor_estimado' => 'nullable|numeric|min:0',
            'observacoes' => 'nullable|string',
            'proximo_passo' => 'nullable|string',
        ]);

        if (auth()->user()->temEscopoDeRevenda()) {
            $data['revenda_id'] = auth()->user()->revenda_id;
        }

        $lead->update($data);

        return redirect()->route('leads.index', ['lead' => $lead->id])->with('status', 'Lead atualizado.');
    }

    /**
     * Uma linha na conversa do lead — data da ligação, o que ficou
     * combinado, o que falta. `?lead=` na volta reabre o painel de onde o
     * comentário saiu; sem ele a página recarregava e o card fechava sozinho.
     */
    public function comentar(Request $request, Lead $lead)
    {
        $this->autorizarAcesso($lead);

        $data = $request->validate(['texto' => 'required|string|max:2000']);

        $lead->comentarios()->create(['autor_id' => auth()->id(), 'texto' => $data['texto']]);

        return redirect()->route('leads.index', ['lead' => $lead->id])->with('status', 'Comentário adicionado.');
    }

    /** As regras do arquivo, iguais nas duas telas que anexam (ver `TarefaController`). */
    private function regrasDeAnexoLead(): array
    {
        return [
            'anexos' => 'required|array|min:1|max:3',
            'anexos.*' => 'required|file|mimes:'.implode(',', LeadAnexo::EXTENSOES_VALIDADAS).'|max:12288',
        ];
    }

    public function anexarArquivo(Request $request, Lead $lead)
    {
        $this->autorizarAcesso($lead);

        $request->validate($this->regrasDeAnexoLead(), [
            'anexos.*.mimes' => 'O lead aceita imagem, PDF, texto, CSV e planilha do Excel.',
            'anexos.*.max' => 'Cada arquivo precisa ter até 12 MB.',
            'anexos.max' => 'Até três arquivos por vez.',
        ]);

        foreach ($request->file('anexos') as $arquivo) {
            // Extensão deduzida do CONTEÚDO, nunca do nome enviado — mesmo
            // motivo do `TarefaController::gravarAnexos()`: o nome no disco
            // não pode depender do que quem envia escreveu no navegador.
            $extensao = $arquivo->guessExtension() ?: 'bin';
            $nomeArquivo = \Illuminate\Support\Str::random(40).'.'.$extensao;
            $caminho = $arquivo->storeAs('lead-anexos', $nomeArquivo, 'public');

            $lead->anexos()->create([
                'autor_id' => auth()->id(),
                'nome_original' => $arquivo->getClientOriginalName(),
                'nome_arquivo' => $nomeArquivo,
                'mime' => $arquivo->getMimeType(),
                'caminho' => $caminho,
                'tamanho' => $arquivo->getSize(),
            ]);
        }

        return redirect()->route('leads.index', ['lead' => $lead->id])->with('status', 'Anexo adicionado.');
    }

    public function excluirAnexo(LeadAnexo $anexo)
    {
        $this->autorizarAcesso($anexo->lead);

        $leadId = $anexo->lead_id;
        $anexo->delete();

        return redirect()->route('leads.index', ['lead' => $leadId])->with('status', 'Anexo removido.');
    }

    public function verAnexo(LeadAnexo $anexo)
    {
        $this->autorizarAcesso($anexo->lead);

        abort_unless(\Illuminate\Support\Facades\Storage::disk('public')->exists($anexo->caminho), 404, 'Anexo não encontrado no servidor.');

        return \Illuminate\Support\Facades\Storage::disk('public')->response($anexo->caminho, $anexo->nome_original, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $anexo->mime ?: 'application/octet-stream',
        ]);
    }

    public function mover(Request $request, Lead $lead)
    {
        $this->autorizarAcesso($lead);

        $data = $request->validate([
            'estagio' => 'required|in:'.implode(',', array_keys(Lead::ESTAGIOS)),
            'motivo_perda' => 'nullable|required_if:estagio,perdido|in:'.implode(',', array_keys(Lead::MOTIVOS_PERDA)),
        ]);

        if ($data['estagio'] === 'cliente_ativo') {
            $lead->converterParaCliente();

            return redirect()->route('leads.index')->with('status', "{$lead->nome} convertido em cliente!");
        }

        $lead->moverEstagio($data['estagio'], $data['motivo_perda'] ?? null);

        return redirect()->route('leads.index')->with('status', 'Lead movido para '.Lead::ESTAGIOS[$data['estagio']].'.');
    }

    public function destroy(Lead $lead)
    {
        $this->autorizarAcesso($lead);

        $lead->delete();

        return redirect()->route('leads.index')->with('status', 'Lead removido.');
    }

    private function autorizarAcesso(Lead $lead): void
    {
        $user = auth()->user();

        if ($user->temEscopoDeRevenda() && $lead->revenda_id !== $user->revenda_id) {
            abort(403, 'Você só pode acessar os leads da sua revenda.');
        }
    }
}
