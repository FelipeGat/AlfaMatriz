<?php
/**
 * Grava UM contato identificado pelo Pedro no Gestor de produção (T-478, com a T-479 do
 * Gestor): a pessoa que disse nome e função no WhatsApp vira contato do cliente em
 * `cliente_contatos_identificados`. O runner copia este modelo, troca __DADOS__ pelo
 * pedido (JSON em base64) e roda pela ponte. Sem APPLY=1 é ensaio: faz e desfaz.
 *
 * O upsert é o combinado com a central do Gestor em 26/10/2026, e não muda sem ela:
 *   - a chave única é (cliente_id, telefone_chave); a chave vem calculada pelo Matriz
 *     (ContatosDoGestor::chave, a mesma regra do Gestor) e é conferida aqui de novo;
 *   - linha que já existe NÃO tem nome nem papel alterados (decisão do dono: a correção
 *     feita à mão na tela do Gestor vale); só telefone, visto_em e updated_at;
 *   - o contato apagado volta (deleted_at = NULL), com o nome e a função que já tinha.
 *
 * Saída: UMA linha "SUPORTE_GESTOR_CONTATO:{json}".
 */
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$d = json_decode(base64_decode('__DADOS__'), true);
$aplicar = getenv('APPLY') === '1';
$saida = fn (array $r) => print('SUPORTE_GESTOR_CONTATO:'.json_encode($r, JSON_UNESCAPED_UNICODE)."\n");

try {
    if (! Schema::hasTable('cliente_contatos_identificados')) {
        throw new RuntimeException('o Gestor ainda não tem a tabela cliente_contatos_identificados');
    }
    $nome = trim((string) ($d['nome'] ?? ''));
    $chave = (string) ($d['telefone_chave'] ?? '');
    if (! is_array($d) || empty($d['cliente_id']) || $nome === '' || ! preg_match('/^\d{10}$/', $chave)) {
        throw new RuntimeException('pedido incompleto');
    }
    // A mesma regra do Gestor: 55 + 10 ou 11 dígitos; a chave tem de bater com o telefone.
    $digitos = preg_replace('/\D/', '', (string) ($d['telefone'] ?? ''));
    if (! str_starts_with($digitos, '55') || ! in_array(strlen($digitos), [12, 13], true) || substr($digitos, 2, 2).substr($digitos, -8) !== $chave) {
        throw new RuntimeException('telefone fora da regra da chave do Gestor');
    }
    $cliente = DB::table('clientes')->where('id', (int) $d['cliente_id'])->whereNull('deleted_at')->value('id');
    if (! $cliente) {
        throw new RuntimeException('cliente inexistente ou excluído no Gestor');
    }
    // Os tamanhos da tabela (nome 120, papel 60), por caractere: o MySQL do Gestor é estrito.
    $nome = mb_substr($nome, 0, 120);
    $papel = mb_substr(trim((string) ($d['papel'] ?? '')), 0, 60);

    DB::beginTransaction();
    DB::statement('INSERT INTO cliente_contatos_identificados (cliente_id, nome, papel, telefone, telefone_chave, origem, visto_em, created_at, updated_at) '
        ."VALUES (?, ?, ?, ?, ?, 'whatsapp', NOW(), NOW(), NOW()) "
        .'ON DUPLICATE KEY UPDATE telefone = VALUES(telefone), visto_em = VALUES(visto_em), updated_at = VALUES(updated_at), deleted_at = NULL',
        [(int) $cliente, $nome, $papel !== '' ? $papel : null, $digitos, $chave]);
    $linha = DB::table('cliente_contatos_identificados')->where('cliente_id', (int) $cliente)->where('telefone_chave', $chave)->first(['id', 'nome', 'papel']);
    $r = ['ok' => true, 'id' => (int) $linha->id, 'nome' => (string) $linha->nome, 'papel' => (string) ($linha->papel ?? '')];
    if ($aplicar) {
        DB::commit();
    } else {
        DB::rollBack();
        $r['ensaio'] = true;
    }
    $saida($r);
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $saida(['ok' => false, 'erro' => mb_substr($e->getMessage(), 0, 200)]);
}
