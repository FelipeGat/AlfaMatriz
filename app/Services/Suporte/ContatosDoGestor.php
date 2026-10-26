<?php

namespace App\Services\Suporte;

use App\Models\SuporteConversa;
use App\Models\SuporteGestorContato;

/**
 * A pessoa identificada ao Pedro vira contato do cliente no Gestor (T-478, com a T-479 do
 * Gestor), e o contato do Gestor identifica a pessoa na conversa seguinte.
 *
 * Decisões do dono (22 e 26/10/2026), que moram no Gestor e valem aqui:
 *   - o contato é REGISTRO, não permissão: pedido sensível continua só dos telefones do
 *     cadastro do cliente, por isso os contatos vêm no retrato separados de "telefones";
 *   - a correção feita à mão no Gestor vale: o upsert de lá não mexe em nome nem em papel
 *     de quem já existe, e o apagado volta, com o nome que tinha, quando a pessoa se
 *     identifica de novo.
 */
class ContatosDoGestor
{
    /**
     * A chave do telefone, IGUAL à do Gestor (`telefone_chave`, char 10): DDD + 8 últimos
     * dígitos, só para 55 + 10 ou 11 dígitos. A do WhatsApp (CanalWhatsApp::chave) aceita
     * número sem o 55, e o Gestor recusaria ou cortaria a linha; aqui, sem o 55, não grava.
     */
    public static function chave(string $numero): ?string
    {
        $d = preg_replace('/\D/', '', $numero);

        return str_starts_with($d, '55') && in_array(strlen($d), [12, 13], true) ? substr($d, 2, 2).substr($d, -8) : null;
    }

    /**
     * Põe na fila da máquina a pessoa identificada nesta conversa. Só cliente do Gestor
     * (pré-cliente não tem contato lá), e uma vez por (cliente, telefone, nome, função):
     * o upsert do Gestor não duplica, mas a ponte é lenta e tem fila. Os tamanhos são os da
     * tabela do Gestor (nome 120, papel 60, telefone só dígitos), em modo estrito: lá, o que
     * passa do tamanho dá erro em vez de ser cortado.
     */
    public function registrar(SuporteConversa $c): ?SuporteGestorContato
    {
        $chave = self::chave((string) $c->telefone);
        if (! $c->identificada() || (int) $c->gestor_cliente_id <= 0 || ! $chave) {
            return null;
        }
        $dados = ['gestor_cliente_id' => (int) $c->gestor_cliente_id, 'telefone_chave' => $chave,
            'nome' => mb_substr(trim((string) $c->solicitante_nome), 0, 120), 'papel' => filled($c->solicitante_funcao) ? mb_substr(trim((string) $c->solicitante_funcao), 0, 60) : null];
        if (SuporteGestorContato::where($dados)->whereIn('situacao', ['pendente', 'reservado', 'feito'])->exists()) {
            return null;
        }

        return SuporteGestorContato::create($dados + ['conversa_id' => $c->id, 'telefone' => preg_replace('/\D/', '', (string) $c->telefone)]);
    }

    /**
     * O Gestor já tem a tabela? O retrato só traz "contatos" nos clientes quando ela existe:
     * até a T-479 chegar à produção do Gestor, a fila espera em vez de falhar linha por linha.
     */
    public function disponivel(): bool
    {
        return collect(app(DiretorioGestor::class)->retrato()['clientes'])->contains(fn ($cl) => is_array($cl['contatos'] ?? null));
    }

    /**
     * Quem já se identificou em outra conversa, reconhecido pelo número: a conversa nasce
     * identificada com o nome e a função do Gestor (inclusive a corrigida à mão lá). Só se
     * o número for contato de UM cliente só; de dois (o porteiro de dois condomínios), a
     * identificação segue perguntando. Outra pessoa no mesmo telefone se apresenta, e o
     * Pedro passa a tratá-la pelo nome novo (Contexto, "QUEM ESCREVE").
     */
    public function reconhecer(SuporteConversa $c): bool
    {
        $chave = self::chave((string) $c->telefone);
        if ($c->identificada() || ! $chave) {
            return false;
        }
        $achados = app(DiretorioGestor::class)->contatoPorChave($chave);
        if ($c->gestor_cliente_id) {
            $achados = array_values(array_filter($achados, fn ($a) => (int) $a['cliente_id'] === (int) $c->gestor_cliente_id));
        }
        if (count(array_unique(array_column($achados, 'cliente_id'))) !== 1) {
            return false;
        }
        $a = $achados[0];
        $c->update(['gestor_cliente_id' => (int) $a['cliente_id'], 'solicitante_nome' => mb_substr($a['nome'], 0, 120),
            'solicitante_funcao' => filled($a['papel']) ? mb_substr((string) $a['papel'], 0, 60) : null, 'identificada_em' => now()]);

        return true;
    }
}
