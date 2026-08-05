<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $clientes = Cliente::with(['revenda', 'sistemas'])
            ->when($request->busca, fn ($q) => $q->where('nome', 'like', "%{$request->busca}%"))
            ->when($request->revenda_id, fn ($q) => $q->where('revenda_id', $request->revenda_id))
            ->orderBy('nome')
            ->paginate(15)
            ->withQueryString();

        $revendas = Revenda::orderBy('nome')->get();

        return view('clientes.index', compact('clientes', 'revendas'));
    }

    public function create()
    {
        $revendas = Revenda::where('ativo', true)->orderBy('nome')->get();
        $sistemas = Sistema::where('ativo', true)->orderBy('nome')->get();

        return view('clientes.create', compact('revendas', 'sistemas'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data = $this->prepararDados($request, $data);

        $cliente = Cliente::create($data);

        $this->sincronizarSistemas($cliente, $request->input('sistemas', []));
        $this->sincronizarEmails($cliente, $request->input('emails', []));
        $this->sincronizarTelefones($cliente, $request->input('telefones', []));

        return redirect()->route('clientes.index')->with('status', 'Cliente cadastrado com sucesso.');
    }

    public function edit(Cliente $cliente)
    {
        $revendas = Revenda::where('ativo', true)->orderBy('nome')->get();
        $sistemas = Sistema::where('ativo', true)->orderBy('nome')->get();
        $cliente->load('sistemas', 'emails', 'telefones');

        return view('clientes.edit', compact('cliente', 'revendas', 'sistemas'));
    }

    public function update(Request $request, Cliente $cliente)
    {
        $data = $this->validated($request, $cliente->id);
        $data = $this->prepararDados($request, $data);

        $cliente->update($data);

        $this->sincronizarSistemas($cliente, $request->input('sistemas', []));
        $this->sincronizarEmails($cliente, $request->input('emails', []));
        $this->sincronizarTelefones($cliente, $request->input('telefones', []));

        return redirect()->route('clientes.index')->with('status', 'Cliente atualizado com sucesso.');
    }

    public function destroy(Cliente $cliente)
    {
        $cliente->delete();

        return redirect()->route('clientes.index')->with('status', 'Cliente removido.');
    }

    /**
     * Não usa sync() puro: desmarcar um sistema deve "cancelar" o vínculo
     * (ativo=false + cancelado_em), não apagar a linha — senão perde o
     * histórico usado pra calcular clientes cancelados/churn na tela de Produtos.
     */
    private function sincronizarSistemas(Cliente $cliente, array $sistemaIdsSelecionados): void
    {
        $jaVinculados = $cliente->sistemas()->pluck('sistemas.id')->all();

        foreach ($sistemaIdsSelecionados as $id) {
            if (in_array($id, $jaVinculados)) {
                $cliente->sistemas()->updateExistingPivot($id, ['ativo' => true, 'cancelado_em' => null]);
            } else {
                $cliente->sistemas()->attach($id, ['ativo' => true, 'ativado_em' => now()->toDateString()]);
            }
        }

        $desmarcados = array_diff($jaVinculados, $sistemaIdsSelecionados);
        foreach ($desmarcados as $id) {
            $cliente->sistemas()->updateExistingPivot($id, ['ativo' => false, 'cancelado_em' => now()->toDateString()]);
        }
    }

    /**
     * Re-sincroniza e-mails/telefones do zero a cada save (mesmo padrão destrutivo do Gestor.Alfa —
     * mais simples que diff, e a lista raramente passa de 2-3 itens).
     */
    private function sincronizarEmails(Cliente $cliente, array $emails): void
    {
        $cliente->emails()->delete();

        $temPrincipal = false;
        foreach ($emails as $item) {
            if (empty($item['email'] ?? null)) {
                continue;
            }

            $principal = ! $temPrincipal && ! empty($item['principal']);
            $temPrincipal = $temPrincipal || $principal;

            $cliente->emails()->create([
                'email' => $item['email'],
                'principal' => $principal,
                'financeiro' => ! empty($item['financeiro']),
            ]);
        }

        if (! $temPrincipal && $cliente->emails()->exists()) {
            $cliente->emails()->first()->update(['principal' => true]);
        }
    }

    private function sincronizarTelefones(Cliente $cliente, array $telefones): void
    {
        $cliente->telefones()->delete();

        $temPrincipal = false;
        foreach ($telefones as $item) {
            if (empty($item['telefone'] ?? null)) {
                continue;
            }

            $principal = ! $temPrincipal && ! empty($item['principal']);
            $temPrincipal = $temPrincipal || $principal;

            $cliente->telefones()->create([
                'telefone' => $item['telefone'],
                'principal' => $principal,
            ]);
        }

        if (! $temPrincipal && $cliente->telefones()->exists()) {
            $cliente->telefones()->first()->update(['principal' => true]);
        }
    }

    /**
     * Resolve nome/razão social/nome_fantasia (mesma regra do Gestor.Alfa) e zera
     * valor_mensal/dia_vencimento quando o cliente é AVULSO.
     */
    private function prepararDados(Request $request, array $data): array
    {
        $data['ativo'] = $request->boolean('ativo', true);
        $data['nota_fiscal'] = $request->boolean('nota_fiscal');

        if ($data['tipo_pessoa'] === 'PF') {
            $data['nome'] = trim($data['nome'] ?? '');
            $data['razao_social'] = $data['nome'];
            $data['nome_fantasia'] = null;
        } else {
            $data['nome'] = $data['nome_fantasia'] ?: $data['razao_social'];
        }

        if (! empty($data['cpf_cnpj'])) {
            $data['cpf_cnpj'] = preg_replace('/\D/', '', $data['cpf_cnpj']);
        }

        if (($data['tipo_cliente'] ?? 'AVULSO') === 'AVULSO') {
            $data['valor_mensal'] = null;
            $data['dia_vencimento'] = null;
        } elseif (! empty($data['dia_vencimento'])) {
            $data['dia_vencimento'] = min((int) $data['dia_vencimento'], 28);
        }

        return $data;
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'revenda_id' => 'nullable|exists:revendas,id',
            'tipo_pessoa' => 'required|in:PF,PJ',
            'nome' => 'required_if:tipo_pessoa,PF|nullable|string|max:255',
            'razao_social' => 'required_if:tipo_pessoa,PJ|nullable|string|max:255',
            'nome_fantasia' => 'nullable|string|max:255',
            'cpf_cnpj' => [
                'nullable', 'string', 'max:20',
                Rule::unique('clientes', 'cpf_cnpj')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'tipo_cliente' => 'required|in:CONTRATO,AVULSO',
            'valor_mensal' => 'nullable|required_if:tipo_cliente,CONTRATO|numeric|min:0',
            'dia_vencimento' => 'nullable|required_if:tipo_cliente,CONTRATO|integer|between:1,28',
            'forma_pagamento_recebimento' => 'nullable|in:boleto,faturado',
            'data_cadastro' => 'nullable|date',
            'cep' => 'nullable|string|max:10',
            'logradouro' => 'nullable|string|max:255',
            'numero' => 'nullable|string|max:20',
            'bairro' => 'nullable|string|max:100',
            'cidade' => 'nullable|string|max:100',
            'uf' => 'nullable|string|max:2',
            'complemento' => 'nullable|string|max:100',
            'inscricao_estadual' => 'nullable|string|max:30',
            'inscricao_municipal' => 'nullable|string|max:30',
            'observacoes' => 'nullable|string',
            'emails' => 'nullable|array',
            'emails.*.email' => 'nullable|email|max:255',
            'telefones' => 'nullable|array',
            'telefones.*.telefone' => 'nullable|string|max:30',
        ]);
    }
}
