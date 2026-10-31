<?php

namespace App\Http\Controllers;

use App\Models\CentralIaRunner;
use App\Models\SuporteGestorAbertura;
use App\Services\Suporte\Atendimento;
use App\Services\Suporte\CanalWhatsApp;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\Pedro;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A porta do runner para o Pedro (docs/specs/suporte-pedro-whatsapp): a máquina
 * da conta configurada pega o próximo pedido e devolve o que a IA respondeu.
 */
class SuportePedroRunnerController extends Controller
{
    public function proxima(Request $request, Pedro $pedro): JsonResponse|Response
    {
        $maquina = $this->maquina($request);
        $job = $pedro->reservar($request->user(), $maquina);

        return $job ? response()->json($job) : response()->noContent();
    }

    public function resposta(Request $request, int $pedido, Pedro $pedro): JsonResponse
    {
        $this->maquina($request);
        $d = $request->validate(['texto' => ['nullable', 'string', 'max:40000'], 'erro' => ['nullable', 'string', 'max:500'],
            'transcricoes' => ['nullable', 'array', 'max:5'], 'transcricoes.*.id' => ['required', 'integer'], 'transcricoes.*.texto' => ['required', 'string', 'max:8000']]);
        $pedro->concluir($request->user(), $pedido, $d['texto'] ?? null, $d['erro'] ?? null, $d['transcricoes'] ?? []);

        return response()->json(['ok' => true]);
    }

    /** O retrato do Gestor (só leitura, a cada 10 min): clientes ativos com telefones e atendimentos recentes. */
    /**
     * Cada item da lista pelas regras dele; o erro sai com o caminho completo ("clientes.12.nome"),
     * como a regra curinga daria. Só o que as regras conhecem fica.
     */
    private function itens(string $lista, array $itens, array $regras): array
    {
        $ok = [];
        foreach (array_values($itens) as $i => $item) {
            $v = \Illuminate\Support\Facades\Validator::make(is_array($item) ? $item : [], $regras);
            if ($v->fails()) {
                throw \Illuminate\Validation\ValidationException::withMessages(collect($v->errors()->messages())
                    ->mapWithKeys(fn ($m, $campo) => ["{$lista}.{$i}.{$campo}" => $m])->all());
            }
            $ok[] = $v->validated();
        }

        return $ok;
    }

    public function gestor(Request $request, DiretorioGestor $diretorio): JsonResponse
    {
        $maquina = $this->maquina($request);
        $this->exigirConta($request);
        // Item a item, e não com "clientes.*.campo": a regra curinga do Laravel cresce ao quadrado —
        // com os pré-clientes (22/10/2026) o retrato passou de 3 mil linhas, levava ~100 s para validar
        // e o runner desistia aos 20 s; o Pedro ficou com o retrato velho. As regras são as mesmas.
        $d = $request->validate([
            'lido_em' => ['required', 'date'],
            'clientes' => ['present', 'array', 'max:20000'], 'atendimentos' => ['present', 'array', 'max:20000'],
            'equipe' => ['nullable', 'array', 'max:500'], 'assuntos' => ['nullable', 'array', 'max:2000'], 'showroom' => ['nullable', 'array', 'max:500'], 'visitas' => ['nullable', 'array', 'max:5000'], 'orcamentos' => ['nullable', 'array', 'max:5000'],
        ]);
        $d['clientes'] = $this->itens('clientes', $d['clientes'], [
            'id' => ['required', 'integer'],
            'nome' => ['nullable', 'string', 'max:255'], 'fantasia' => ['nullable', 'string', 'max:255'], 'razao' => ['nullable', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:30'], 'cidade' => ['nullable', 'string', 'max:120'], 'empresa' => ['nullable', 'string', 'max:120'],
            'telefones' => ['nullable', 'array', 'max:20'], 'telefones.*' => ['string', 'max:40'], 'empresa_id' => ['nullable', 'integer'],
            'portal' => ['nullable', 'array', 'max:5'], 'portal.*' => ['string', 'email', 'max:255'],
            // Pré-cliente do funil (id negativo), desde 22/10/2026.
            'pre' => ['nullable', 'boolean'],
            // Quem se identificou ao Pedro (T-479 do Gestor). Ausente = o Gestor ainda não tem a tabela.
            'contatos' => ['sometimes', 'array', 'max:50'], 'contatos.*.nome' => ['required', 'string', 'max:255'], 'contatos.*.papel' => ['nullable', 'string', 'max:255'],
            'contatos.*.telefone' => ['nullable', 'string', 'max:40'], 'contatos.*.chave' => ['nullable', 'string', 'size:10'],
        ]);
        $d['atendimentos'] = $this->itens('atendimentos', $d['atendimentos'], [
            'id' => ['required', 'integer'], 'cliente_id' => ['nullable', 'integer'], 'numero' => ['nullable', 'string', 'max:30'], 'solicitante' => ['nullable', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:500'], 'status' => ['nullable', 'string', 'max:40'], 'criado_em' => ['nullable', 'string', 'max:30'],
            'empresa_id' => ['nullable', 'integer'], 'assunto_id' => ['nullable', 'integer'],
            'funcionarios' => ['nullable', 'array', 'max:20'], 'funcionarios.*' => ['integer'],
        ]);
        $d['equipe'] = $this->itens('equipe', $d['equipe'] ?? [], ['id' => ['required', 'integer'], 'nome' => ['required', 'string', 'max:255'],
            'telefones' => ['nullable', 'array', 'max:5'], 'telefones.*' => ['string', 'max:40']]);
        $d['assuntos'] = $this->itens('assuntos', $d['assuntos'] ?? [], ['id' => ['required', 'integer'], 'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:60'], 'categoria' => ['nullable', 'string', 'max:120'], 'empresa_id' => ['nullable', 'integer']]);
        $d['showroom'] = $this->itens('showroom', $d['showroom'] ?? [], ['categoria' => ['nullable', 'string', 'max:120'], 'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:500'], 'texto' => ['nullable', 'string', 'max:2000'],
            'url' => ['nullable', 'string', 'max:500'], 'video' => ['nullable', 'string', 'max:500']]);
        // A agenda dos técnicos (pedro-dia-do-tecnico): situação como as constantes do AtendimentoVisita do Gestor.
        $d['visitas'] = $this->itens('visitas', $d['visitas'] ?? [], ['id' => ['required', 'integer'], 'atendimento_id' => ['required', 'integer'],
            'tecnico_id' => ['nullable', 'integer'], 'inicio' => ['required', 'date_format:Y-m-d H:i'], 'fim' => ['nullable', 'date_format:Y-m-d H:i'],
            'situacao' => ['required', 'string', 'max:30'], 'observacoes' => ['nullable', 'string', 'max:300'],
            'adicionais' => ['nullable', 'array', 'max:20'], 'adicionais.*' => ['integer']]);
        $d['orcamentos'] = $this->itens('orcamentos', $d['orcamentos'] ?? [], ['id' => ['required', 'integer'], 'numero' => ['nullable', 'string', 'max:30'],
            'cliente_id' => ['nullable', 'integer'], 'atendimento_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:40'],
            'descricao' => ['nullable', 'string', 'max:200'], 'agendado_para' => ['nullable', 'date_format:Y-m-d']]);
        $diretorio->gravar($maquina, \Illuminate\Support\Carbon::parse($d['lido_em'])->setTimezone(config('app.timezone'))->toDateTimeString(), $d['clientes'], $d['atendimentos'], $d['assuntos'] ?? [], $d['equipe'] ?? [], $d['showroom'] ?? [], $d['visitas'], $d['orcamentos']);

        return response()->json(['ok' => true, 'clientes' => count($d['clientes'])]);
    }

    /** Os atendimentos que o Pedro pediu para abrir no Gestor (a máquina grava pela ponte). */
    public function aberturas(Request $request): JsonResponse
    {
        $maquina = $this->maquina($request);
        $this->exigirConta($request);
        $lista = DB::transaction(function () use ($maquina) {
            // Reservado há mais de 10 min (máquina caiu no meio): volta a ser oferecido? Não — pode ter gravado; fica para a equipe
            // conferir, e quem pediu é avisado disso (Pedro::vencidos, T-494). O resultado que chegar depois ainda vale.
            $p = SuporteGestorAbertura::where('situacao', 'pendente')->orderBy('id')->lockForUpdate()->limit(5)->get();
            foreach ($p as $a) {
                $a->update(['situacao' => 'reservado', 'reservado_em' => now()]);
            }

            return $p;
        });

        return response()->json(['aberturas' => $lista->map(fn ($a) => ['id' => $a->id, 'cliente_id' => $a->gestor_cliente_id > 0 ? $a->gestor_cliente_id : null, 'assunto_id' => $a->assunto_id, 'empresa_id' => $a->empresa_id, 'descricao' => $a->descricao,
            'solicitante' => $a->solicitante, 'telefone' => $a->telefone, 'prioridade' => $a->prioridade,
            'modo' => $a->modo ?: 'novo', 'atendimento' => $a->atendimento_numero, 'autor_email' => config('services.suporte_pedro.conta'),
            'funcionario_id' => $a->funcionario_id, 'agendar_data' => $a->agendar_data?->toDateString(), 'periodo' => $a->agendar_periodo,
            // Pré-cliente (id negativo no retrato) e pré-cliente novo; horário de início e fim da agenda (22/10/2026).
            'pre_cliente_id' => $a->gestor_cliente_id < 0 ? -$a->gestor_cliente_id : null, 'novo_pre' => $a->novo_pre,
            'hora_inicio' => $a->hora_inicio, 'hora_fim' => $a->hora_fim])->values()]);
    }

    public function aberturaFeita(Request $request, int $abertura, Atendimento $atendimento, CanalWhatsApp $canal): JsonResponse
    {
        $this->maquina($request);
        $this->exigirConta($request);
        $d = $request->validate(['numero' => ['nullable', 'string', 'max:30'], 'erro' => ['nullable', 'string', 'max:300'], 'modo' => ['nullable', 'in:novo,vincular,reabrir'],
            'agendado' => ['nullable', 'boolean'], 'agendamento_erro' => ['nullable', 'string', 'max:300']]);
        $a = SuporteGestorAbertura::with('conversa')->whereKey($abertura)->where('situacao', 'reservado')->firstOrFail();
        // O Gestor pode ter trocado o modo (o vinculado já tinha sido concluído: reabriu).
        if (! empty($d['modo'])) {
            $a->modo = $d['modo'];
        }
        // O que a agenda do Gestor respondeu: vai na mensagem a quem pediu.
        if ($a->agendar_data) {
            $a->agendamento = ! empty($d['agendado']) ? 'agendado' : 'não agendado: '.mb_substr((string) ($d['agendamento_erro'] ?? 'sem resposta da agenda'), 0, 200);
        }
        $atendimento->concluirNoGestor($a, $d['numero'] ?? null, $d['erro'] ?? null, $canal);

        return response()->json(['ok' => true]);
    }

    /** Até 5 mudanças de agenda pendentes, reservadas para esta máquina (pedro-dia-do-tecnico, contrato §2). */
    public function agenda(Request $request): JsonResponse
    {
        $this->maquina($request);
        $this->exigirConta($request);
        $lista = DB::transaction(function () {
            $m = \App\Models\SuporteGestorAgenda::where('situacao', 'pendente')->orderBy('id')->lockForUpdate()->limit(5)->get();
            foreach ($m as $x) {
                $x->update(['situacao' => 'reservado', 'reservado_em' => now()]);
            }

            return $m;
        });

        return response()->json(['mudancas' => $lista->map(fn ($m) => ['id' => $m->id, 'chave' => $m->chave, 'tipo' => $m->tipo, 'visita_id' => $m->visita_id,
            'atendimento' => $m->atendimento, 'tecnico_id' => $m->tecnico_id, 'inicio' => $m->inicio, 'fim' => $m->fim, 'esperado' => $m->esperado,
            'motivo' => $m->motivo, 'observacao' => $m->observacao, 'pedido_por' => $m->pedido_por, 'autor_email' => config('services.suporte_pedro.conta')])->values()]);
    }

    public function agendaFeita(Request $request, int $mudanca, \App\Services\Suporte\AgendaDoTecnico $agenda, CanalWhatsApp $canal): JsonResponse
    {
        $this->maquina($request);
        $this->exigirConta($request);
        $d = $request->validate(['ok' => ['required', 'boolean'], 'erro' => ['nullable', 'string', 'max:300'], 'visita_id' => ['nullable', 'integer'],
            'numero' => ['nullable', 'string', 'max:30'], 'ja_estava' => ['nullable', 'boolean']]);
        $m = \App\Models\SuporteGestorAgenda::whereKey($mudanca)->where('situacao', 'reservado')->firstOrFail();
        $m->update($d['ok'] ? ['situacao' => 'feito', 'erro' => null, 'resultado' => array_filter(['visita_id' => $d['visita_id'] ?? null, 'numero' => $d['numero'] ?? null, 'ja_estava' => $d['ja_estava'] ?? null], fn ($v) => $v !== null)]
            : ['situacao' => 'falhou', 'erro' => mb_substr((string) ($d['erro'] ?? 'o Gestor não gravou'), 0, 300)]);
        $agenda->concluirLote($m->lote, $canal);

        return response()->json(['ok' => true]);
    }

    /**
     * Até 10 contatos identificados para a máquina gravar no Gestor (T-478/T-479). Enquanto o
     * retrato não traz "contatos" (a tabela ainda não chegou ao Gestor da produção), nada sai:
     * a fila espera em vez de falhar linha por linha.
     */
    public function contatos(Request $request, \App\Services\Suporte\ContatosDoGestor $contatos): JsonResponse
    {
        $this->maquina($request);
        $this->exigirConta($request);
        if (! $contatos->disponivel()) {
            return response()->json(['contatos' => []]);
        }
        $lista = DB::transaction(function () {
            $p = \App\Models\SuporteGestorContato::where('situacao', 'pendente')->orderBy('id')->lockForUpdate()->limit(10)->get();
            foreach ($p as $x) {
                $x->update(['situacao' => 'reservado', 'reservado_em' => now()]);
            }

            return $p;
        });

        return response()->json(['contatos' => $lista->map(fn ($x) => ['id' => $x->id, 'cliente_id' => $x->gestor_cliente_id, 'nome' => $x->nome, 'papel' => $x->papel,
            'telefone' => $x->telefone, 'telefone_chave' => $x->telefone_chave])->values()]);
    }

    public function contatoFeito(Request $request, int $contato): JsonResponse
    {
        $this->maquina($request);
        $this->exigirConta($request);
        $d = $request->validate(['ok' => ['required', 'boolean'], 'erro' => ['nullable', 'string', 'max:300']]);
        $x = \App\Models\SuporteGestorContato::whereKey($contato)->where('situacao', 'reservado')->firstOrFail();
        $x->update($d['ok'] ? ['situacao' => 'feito', 'erro' => null] : ['situacao' => 'falhou', 'erro' => mb_substr((string) ($d['erro'] ?? 'o Gestor não gravou'), 0, 300)]);

        return response()->json(['ok' => true]);
    }

    private function exigirConta(Request $request): void
    {
        abort_unless(mb_strtolower($request->user()->email) === mb_strtolower((string) config('services.suporte_pedro.conta')), 403);
    }

    private function maquina(Request $request): string
    {
        $user = $request->user();
        abort_unless($user->ativo && ! $user->temEscopoDeRevenda(), 403);
        $maquina = (string) $request->input('maquina', '');
        abort_unless(preg_match('/^[A-Za-z0-9._-]{1,64}$/', $maquina) && CentralIaRunner::where('user_id', $user->id)->where('maquina', $maquina)->exists(), 404);

        return $maquina;
    }
}
