<?php

namespace App\Services\Suporte;

use App\Models\SuporteConversa;

/**
 * Quanto o Pedro pode saber de quem escreve, numa regra só (Felipe, 31/10/2026: "o Pedro não
 * pode passar informação nenhuma, nem mesmo NF nem boleto, para quem ele não conhece; ele tem
 * que sempre consultar o telefone cadastrado dentro do cadastro do cliente").
 *
 * Antes, cada trecho do pedido à IA decidia sozinho: o contrato e o histórico saíam para o
 * número fora do cadastro, mas os sistemas, as notas do cliente na base e os alertas dele
 * continuavam indo para quem só disse o nome. Agora o Contexto monta o pedido a partir do
 * NÍVEL, e o que fala com o cliente em nome do Pedro pergunta aqui. Consulta nova de dado do
 * cliente (boleto, NF) também: só no CADASTRO, e o documento vai ao contato do cadastro,
 * nunca à conversa.
 */
class Identificacao
{
    /** Ainda não disse o nome e a empresa: o Pedro só pede a identificação (T-478). */
    public const DESCONHECIDO = 'desconhecido';

    /** Disse quem é, mas o número NÃO é do cadastro do cliente: relata problema e não recebe nada do cadastro. */
    public const PELO_NOME = 'pelo_nome';

    /** O número é um telefone do cadastro do cliente confirmado. */
    public const CADASTRO = 'cadastro';

    /** Número da lista de acesso interno: é da casa, e a conversa é outra (acesso-interno.md). */
    public const INTERNO = 'interno';

    public function nivel(SuporteConversa $c): string
    {
        return match (true) {
            app(AcessoInterno::class)->deQuem((string) $c->telefone) !== null => self::INTERNO,
            ! $c->identificada() => self::DESCONHECIDO,
            $this->doCadastro($c) => self::CADASTRO,
            default => self::PELO_NOME,
        };
    }

    /**
     * O número que escreve é telefone do cadastro de TODO cliente confirmado na conversa? Dizer
     * o nome não basta: qualquer um diz "sou do Castanheiras". O contato que o Pedro grava no
     * Gestor (T-479) também não conta: é registro, não permissão, e `porTelefone` não o vê.
     *
     * Matriz e Gestor ligados (`Cliente::gestor_cliente_id`) são a mesma organização: basta um
     * dos dois cadastros. Sem a ligação são duas, e o número precisa estar nas duas: o síndico
     * profissional que está no cadastro do condomínio A e diz falar pelo B não vê nada do B.
     */
    public function doCadastro(SuporteConversa $c): bool
    {
        $telefone = (string) $c->telefone;
        $matriz = $c->cliente_id ? CanalWhatsApp::clienteDoNumero($telefone) === (int) $c->cliente_id : null;
        $gestor = $c->gestor_cliente_id
            ? collect(app(DiretorioGestor::class)->porTelefone($telefone))->contains(fn ($g) => (int) ($g['id'] ?? 0) === (int) $c->gestor_cliente_id)
            : null;
        if ($matriz === null && $gestor === null) {
            return false;
        }
        if ($matriz !== null && $gestor !== null && (int) $c->cliente?->gestor_cliente_id === (int) $c->gestor_cliente_id) {
            return $matriz || $gestor;
        }

        return $matriz !== false && $gestor !== false;
    }
}
