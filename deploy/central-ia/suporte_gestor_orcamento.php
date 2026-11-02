<?php
/**
 * UM orçamento pedido ao Pedro por um proprietário e confirmado com o "sim"
 * (docs/specs/pedro-orcamento, contrato runner-orcamento §2). Roda DENTRO do Gestor de produção
 * pela ponte da máquina do dono, como o suporte_gestor_agenda.php; sem --apply, faz e desfaz.
 *
 * A regra é toda do Gestor (App\Services\Comercial\OrcamentoPeloPedro, que usa a mesma conta da
 * tela): aqui só o transporte. Sem esse serviço (Gestor antigo), recusa — nunca grava por fora.
 * O usuário de quem pediu fica como o autenticado durante a gravação, para o registro de atividade
 * do Gestor sair no nome dele.
 *
 * Saída: UMA linha "SUPORTE_ORCAMENTO:{json}".
 */
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Comercial\OrcamentoPeloPedro;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$d = json_decode(base64_decode('__DADOS__'), true);
$aplicar = getenv('APPLY') === '1';
$saida = fn (array $r) => print('SUPORTE_ORCAMENTO:'.json_encode($r + ['ensaio' => ! $aplicar], JSON_UNESCAPED_UNICODE)."\n");

try {
    if (! class_exists(OrcamentoPeloPedro::class)) {
        throw new RuntimeException('o Gestor ainda não tem a criação de orçamento pelo Pedro — publicar o Gestor antes');
    }
    if (! is_array($d)) {
        throw new RuntimeException('pedido ilegível');
    }
    if ($autor = DB::table('users')->where('email', (string) ($d['autor_email'] ?? ''))->value('id')) {
        Auth::onceUsingId($autor);
    }
    DB::beginTransaction();
    $resultado = app(OrcamentoPeloPedro::class)->criar($d);
    $aplicar ? DB::commit() : DB::rollBack();
    $saida($resultado);
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // Recusa de regra vai com a frase do Gestor; o resto, com a classe (sem dado do pedido). O erro
    // de banco é RuntimeException também, mas a frase dele traz o SQL com os valores: sai só a classe.
    $msg = ($e instanceof DomainException || $e instanceof RuntimeException) && ! $e instanceof Illuminate\Database\QueryException ? $e->getMessage()
        : ($e instanceof Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e::class.' em '.basename($e->getFile()).':'.$e->getLine());
    $saida(['ok' => false, 'erro' => mb_substr((string) $msg, 0, 280)]);
}
