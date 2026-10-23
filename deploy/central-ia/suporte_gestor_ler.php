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

    // Os pré-clientes do funil (Felipe, 22/10/2026: "não existe no cadastro de clientes nem como
    // pré-cliente, ele tem que ir correlacionando até o cliente certo"). Vão junto dos clientes, marcados
    // com "pre" e com o id NEGATIVO, para não colidir com o do cliente; convertido em cliente fica de fora.
    if (Schema::hasTable('pre_clientes')) {
        foreach (DB::table('pre_clientes')->when($temCol('pre_clientes', 'convertido_em_cliente'), fn ($q) => $q->where(fn ($w) => $w->whereNull('convertido_em_cliente')->orWhere('convertido_em_cliente', 0)))
            ->when($temCol('pre_clientes', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->get() as $p) {
            $clientes[] = [
                'id' => -(int) $p->id,
                'nome' => (string) (($p->nome_fantasia ?? '') ?: ($p->razao_social ?? '')),
                'fantasia' => (string) ($p->nome_fantasia ?? ''),
                'razao' => (string) ($p->razao_social ?? ''),
                'documento' => (string) ($p->cpf_cnpj ?? ''),
                'cidade' => (string) ($p->cidade ?? ''),
                'empresa_id' => isset($p->empresa_id) && $p->empresa_id ? (int) $p->empresa_id : null,
                'empresa' => isset($p->empresa_id) && $p->empresa_id ? (string) ($empresas[$p->empresa_id] ?? '') : '',
                'telefones' => array_values(array_filter([(string) ($p->telefone ?? '')], fn ($t) => strlen(preg_replace('/\D/', '', $t)) >= 10)),
                'portal' => [],
                'pre' => true,
            ];
        }
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
    // A agenda dos técnicos (pedro-dia-do-tecnico, 23/10/2026): as visitas de 7 dias para trás a 14
    // para frente, para o Pedro mostrar o dia de um técnico e, depois, lançar e corrigir.
    $visitas = [];
    if (Schema::hasTable('atendimento_visitas')) {
        $adicionais = [];
        if (Schema::hasTable('atendimento_visita_tecnicos')) {
            foreach (DB::table('atendimento_visita_tecnicos')->get(['atendimento_visita_id', 'funcionario_id']) as $t) {
                $adicionais[$t->atendimento_visita_id][] = (int) $t->funcionario_id;
            }
        }
        foreach (DB::table('atendimento_visitas')->whereNull('deleted_at')
            ->whereBetween('data_inicio', [now()->subDays(7)->startOfDay(), now()->addDays(14)->endOfDay()])
            ->orderBy('data_inicio')->limit(5000)->get(['id', 'atendimento_id', 'funcionario_id', 'data_inicio', 'data_fim', 'status', 'observacoes']) as $v) {
            $visitas[] = ['id' => (int) $v->id, 'atendimento_id' => (int) $v->atendimento_id, 'tecnico_id' => $v->funcionario_id ? (int) $v->funcionario_id : null,
                'inicio' => substr((string) $v->data_inicio, 0, 16), 'fim' => $v->data_fim ? substr((string) $v->data_fim, 0, 16) : null,
                'situacao' => (string) $v->status, 'observacoes' => mb_substr(trim((string) $v->observacoes), 0, 300),
                'adicionais' => $adicionais[$v->id] ?? []];
        }
        // Quem aparece como técnico de visita entra na equipe mesmo sem telefone: o Pedro precisa do nome.
        $naEquipe = array_column($equipe, 'id');
        $daAgenda = array_diff(array_unique(array_filter(array_merge(array_column($visitas, 'tecnico_id'), ...array_column($visitas, 'adicionais')))), $naEquipe);
        foreach ($daAgenda ? DB::table('funcionarios')->whereIn('id', $daAgenda)->get(['id', 'nome']) : [] as $f) {
            $equipe[] = ['id' => (int) $f->id, 'nome' => (string) $f->nome, 'telefones' => []];
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
    // De cliente ou de pré-cliente (este com o id negativo, como na lista de clientes).
    $comPre = $temCol('atendimentos', 'pre_cliente_id');
    foreach (DB::table('atendimentos')->whereNull('deleted_at')->where(fn ($q) => $comPre ? $q->whereNotNull('cliente_id')->orWhereNotNull('pre_cliente_id') : $q->whereNotNull('cliente_id'))
        ->where(fn ($q) => $q->whereNotIn('status_atual', ['concluido', 'cancelado'])->orWhere('created_at', '>=', now()->subDays(120)))
        ->orderByDesc('id')->limit(5000)->get(array_merge(['id', 'numero_atendimento', 'cliente_id', 'descricao', 'status_atual', 'empresa_id', 'assunto_id', 'created_at', 'funcionario_id'], $comPre ? ['pre_cliente_id'] : [])) as $a) {
        $atendimentos[] = ['id' => (int) $a->id, 'numero' => (string) $a->numero_atendimento, 'cliente_id' => $a->cliente_id ? (int) $a->cliente_id : -(int) ($a->pre_cliente_id ?? 0),
            'descricao' => mb_substr(trim((string) $a->descricao), 0, 300), 'status' => (string) $a->status_atual,
            'empresa_id' => $a->empresa_id ? (int) $a->empresa_id : null, 'assunto_id' => $a->assunto_id ? (int) $a->assunto_id : null,
            'criado_em' => (string) $a->created_at,
            'funcionarios' => array_values(array_unique(array_filter(array_merge([$a->funcionario_id ? (int) $a->funcionario_id : null], $tecnicos[$a->id] ?? []))))];
    }

    $assuntos = DB::table('assuntos')->where('ativo', 1)->orderBy('id')->get(['id', 'nome', 'tipo', 'categoria', 'empresa_id'])
        ->map(fn ($a) => ['id' => (int) $a->id, 'nome' => (string) $a->nome, 'tipo' => (string) $a->tipo, 'categoria' => (string) $a->categoria,
            'empresa_id' => $a->empresa_id ? (int) $a->empresa_id : null])->all();

    // A biblioteca do Showroom comercial (Felipe, 20/10/2026: "o Pedro tem que ter como ver as
    // apresentações do comercial"): os textos de cada conteúdo ativo, para ele explicar o que fazemos.
    // As apresentações montadas para um pré-cliente ficam de fora (são de uma reunião específica).
    $showroom = [];
    if (Schema::hasTable('showroom_conteudos') && Schema::hasTable('showroom_categorias')) {
        $textos = function ($valor) use (&$textos): array {
            if (is_array($valor)) {
                return array_merge(...array_map($textos, array_values($valor)) ?: [[]]);
            }
            $t = trim(strip_tags((string) $valor));
            // Imagem embutida, caminho de arquivo, cor e ícone não são texto para o Pedro.
            return $t === '' || mb_strlen($t) > 600 || str_starts_with($t, 'data:') || preg_match('/^(#[0-9a-f]{3,8}|[\w\/.-]+\.(png|jpe?g|webp|svg|gif|mp4)|https?:\/\/\S+\.(png|jpe?g|webp|svg|gif))$/i', $t)
                || preg_match('/^[a-z0-9_-]{1,24}$/', $t) ? [] : [$t];
        };
        $categorias = DB::table('showroom_categorias')->where('ativo', 1)->pluck('nome', 'id');
        foreach (DB::table('showroom_conteudos')->where('ativo', 1)->whereIn('categoria_id', $categorias->keys())->orderBy('categoria_id')->orderBy('ordem')
            ->get(['categoria_id', 'titulo', 'descricao', 'tipo', 'conteudo', 'dados', 'url', 'video_url']) as $c) {
            $texto = implode(' · ', array_unique(array_merge($textos((string) $c->conteudo), $textos(json_decode((string) $c->dados, true) ?? []))));
            $showroom[] = ['categoria' => (string) $categorias[$c->categoria_id], 'titulo' => (string) $c->titulo,
                'descricao' => mb_substr(trim((string) $c->descricao), 0, 300), 'texto' => mb_substr(preg_replace('/\s+/u', ' ', $texto), 0, 1500),
                'url' => (string) ($c->tipo === 'link' ? $c->url : ''), 'video' => (string) ($c->video_url ?? '')];
        }
    }

    echo 'SUPORTE_GESTOR:'.json_encode(['clientes' => $clientes, 'atendimentos' => $atendimentos, 'assuntos' => $assuntos, 'equipe' => $equipe, 'showroom' => $showroom, 'visitas' => $visitas], JSON_UNESCAPED_UNICODE)."\n";
} finally {
    DB::rollBack();
}
