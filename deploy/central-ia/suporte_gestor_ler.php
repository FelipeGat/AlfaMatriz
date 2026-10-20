<?php
/**
 * Retrato do Gestor para o Pedro, do suporte no WhatsApp (docs/specs/suporte-pedro-whatsapp).
 *
 * Roda DENTRO do Gestor de produção pela ponte da máquina do Felipe, a cada 10 min.
 * Só leitura, nas mesmas três camadas do secretario_gestor.php: recusa APPLY=1,
 * sessão READ ONLY e transação desfeita no fim. Aqui só há SELECT.
 *
 * Contatos: o Gestor não guarda telefone em `clientes`; ficam em `telefones` e
 * `cliente_contatos` (migrations 000020 e 000040). Empresa do cliente: a dos
 * atendimentos dele (o pivot `cliente_empresa` é a lista de onde ele é cliente).
 *
 * Saída: UMA linha "SUPORTE_GESTOR:{json}".
 */
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (getenv('APPLY') === '1') {
    fwrite(STDERR, "suporte_gestor_ler.php é só de leitura: rode sem --apply.\n");
    exit(1);
}
DB::statement('SET SESSION TRANSACTION READ ONLY');
DB::beginTransaction();
try {
    $temCol = fn (string $t, string $c) => Schema::hasColumn($t, $c);
    $doc = collect(['cpf_cnpj', 'cnpj', 'documento'])->first(fn ($c) => $temCol('clientes', $c));
    $empresas = DB::table('empresas')->pluck('nome_fantasia', 'id');

    $telefones = [];
    foreach (DB::table('telefones')->whereNotNull('cliente_id')->get(['cliente_id', 'valor']) as $t) {
        $telefones[$t->cliente_id][] = (string) $t->valor;
    }
    if (Schema::hasTable('cliente_contatos')) {
        foreach (DB::table('cliente_contatos')->get(['cliente_id', 'valor']) as $t) {
            if (strlen(preg_replace('/\D/', '', (string) $t->valor)) >= 10) {
                $telefones[$t->cliente_id][] = (string) $t->valor;
            }
        }
    }

    // Login do portal (para o Pedro orientar o boleto): usuário-cliente ativo com o financeiro liberado
    // (`portal_escopos` vazio libera tudo). Só o e-mail; senha nunca sai daqui.
    $portal = [];
    if (Schema::hasTable('cliente_user')) {
        $usuarios = DB::table('users')->join('cliente_user', 'cliente_user.user_id', '=', 'users.id')
            ->where('users.tipo', 'cliente')->where('users.ativo', 1)->whereNull('users.deleted_at')
            ->get(['cliente_user.cliente_id', 'users.email', 'users.portal_escopos']);
        foreach ($usuarios as $u) {
            $escopos = array_filter((array) json_decode((string) $u->portal_escopos, true));
            if ($escopos === [] || in_array('financeiro', $escopos, true)) {
                $portal[$u->cliente_id][] = (string) $u->email;
            }
        }
    }

    // A empresa de cada cliente: a do atendimento mais recente dele.
    $empresaDe = DB::table('atendimentos')->whereNotNull('empresa_id')->whereNotNull('cliente_id')->whereNull('deleted_at')
        ->orderBy('id')->pluck('empresa_id', 'cliente_id');

    $clientes = [];
    foreach (DB::table('clientes')->where('ativo', 1)->whereNull($temCol('clientes', 'deleted_at') ? 'deleted_at' : DB::raw('NULL'))->get() as $c) {
        $clientes[] = [
            'id' => (int) $c->id,
            'nome' => (string) ($c->nome ?? ''),
            'fantasia' => (string) ($c->nome_fantasia ?? ''),
            'razao' => (string) ($c->razao_social ?? ''),
            'documento' => $doc ? (string) ($c->{$doc} ?? '') : '',
            'cidade' => (string) ($c->cidade ?? ''),
            'empresa_id' => isset($empresaDe[$c->id]) ? (int) $empresaDe[$c->id] : null,
            'empresa' => isset($empresaDe[$c->id]) ? (string) ($empresas[$empresaDe[$c->id]] ?? '') : '',
            'telefones' => array_values(array_unique(array_slice($telefones[$c->id] ?? [], 0, 20))),
            'portal' => array_values(array_unique(array_slice($portal[$c->id] ?? [], 0, 5))),
        ];
    }

    // A equipe (Felipe, 20/10/2026): funcionário ativo com telefone, para o Pedro
    // reconhecer quem é da casa e oferecer ajuda com os atendimentos em nome dela.
    $equipe = [];
    foreach (DB::table('funcionarios')->where('ativo', 1)->whereNull('deleted_at')->whereNull('data_demissao')->get(['id', 'nome', 'celular', 'telefone']) as $f) {
        $fones = array_values(array_filter([(string) $f->celular, (string) $f->telefone], fn ($t) => strlen(preg_replace('/\D/', '', $t)) >= 10));
        if ($fones) {
            $equipe[] = ['id' => (int) $f->id, 'nome' => (string) $f->nome, 'telefones' => $fones];
        }
    }
    // Técnicos de cada atendimento (além do funcionário responsável).
    $tecnicos = [];
    if (Schema::hasTable('atendimento_tecnicos')) {
        foreach (DB::table('atendimento_tecnicos')->get(['atendimento_id', 'funcionario_id']) as $t) {
            $tecnicos[$t->atendimento_id][] = (int) $t->funcionario_id;
        }
    }

    // Abertos de qualquer data e os dos últimos 120 dias.
    $atendimentos = [];
    foreach (DB::table('atendimentos')->whereNull('deleted_at')->whereNotNull('cliente_id')
        ->where(fn ($q) => $q->whereNotIn('status_atual', ['concluido', 'cancelado'])->orWhere('created_at', '>=', now()->subDays(120)))
        ->orderByDesc('id')->limit(5000)->get(['id', 'numero_atendimento', 'cliente_id', 'descricao', 'status_atual', 'empresa_id', 'assunto_id', 'created_at', 'funcionario_id']) as $a) {
        $atendimentos[] = ['id' => (int) $a->id, 'numero' => (string) $a->numero_atendimento, 'cliente_id' => (int) $a->cliente_id,
            'descricao' => mb_substr(trim((string) $a->descricao), 0, 300), 'status' => (string) $a->status_atual,
            'empresa_id' => $a->empresa_id ? (int) $a->empresa_id : null, 'assunto_id' => $a->assunto_id ? (int) $a->assunto_id : null,
            'criado_em' => (string) $a->created_at,
            'funcionarios' => array_values(array_unique(array_filter(array_merge([$a->funcionario_id ? (int) $a->funcionario_id : null], $tecnicos[$a->id] ?? []))))];
    }

    $assuntos = DB::table('assuntos')->where('ativo', 1)->orderBy('id')->get(['id', 'nome', 'tipo', 'categoria', 'empresa_id'])
        ->map(fn ($a) => ['id' => (int) $a->id, 'nome' => (string) $a->nome, 'tipo' => (string) $a->tipo, 'categoria' => (string) $a->categoria,
            'empresa_id' => $a->empresa_id ? (int) $a->empresa_id : null])->all();

    echo 'SUPORTE_GESTOR:'.json_encode(['clientes' => $clientes, 'atendimentos' => $atendimentos, 'assuntos' => $assuntos, 'equipe' => $equipe], JSON_UNESCAPED_UNICODE)."\n";
} finally {
    DB::rollBack();
}
