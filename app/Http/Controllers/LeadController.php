<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $leads = Lead::with(['revenda', 'sistema', 'vendedor'])->orderByDesc('created_at')->get();

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

        $revendas = Revenda::where('ativo', true)->orderBy('nome')->get();
        $sistemas = Sistema::where('ativo', true)->orderBy('nome')->get();

        $estagios = $this->estagios($colunas);

        return view('leads.index', compact('colunas', 'kpis', 'revendas', 'sistemas', 'estagios'));
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
                // Desfecho, não trabalho em andamento: ninguém fica lendo card
                // em "cliente ativo" ou "perdido" todo dia. Eles cedem largura
                // para as colunas onde a negociação acontece, e expandem no
                // clique quando alguém precisa olhar.
                'terminal' => in_array($chave, Lead::ESTAGIOS_TERMINAIS, true),
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
        ]);

        $data['estagio'] = 'lead';
        $data['estagio_atualizado_em'] = now();
        $data['vendedor_id'] = auth()->id();

        Lead::create($data);

        return redirect()->route('leads.index')->with('status', 'Lead cadastrado.');
    }

    public function update(Request $request, Lead $lead)
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
        ]);

        $lead->update($data);

        return redirect()->route('leads.index')->with('status', 'Lead atualizado.');
    }

    public function mover(Request $request, Lead $lead)
    {
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
        $lead->delete();

        return redirect()->route('leads.index')->with('status', 'Lead removido.');
    }
}
