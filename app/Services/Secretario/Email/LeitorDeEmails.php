<?php

namespace App\Services\Secretario\Email;

use App\Models\SecretarioEmail;
use App\Models\SecretarioEmailConta;
use App\Services\CentralIa\Eventos;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Lê as caixas da pessoa a cada 10 min (FR-005): na primeira vez os últimos 3
 * dias, depois só o que é novo. Guarda metadados + classificação; o corpo só
 * passa pela memória (FR-004). Abre tudo só para leitura (ClienteImap).
 */
class LeitorDeEmails
{
    public const DIAS_PRIMEIRA = 3;
    public const DIAS_NAO_LIDOS = 3;
    public const DIAS_GUARDA = 30;
    public const MAX_POR_VEZ = 300;

    public function __construct(private Classificador $classificador) {}

    public function cliente(): ClienteImap
    {
        return new ClienteImap(app()->bound(TransporteImap::class) ? app(TransporteImap::class) : new SocketImap);
    }

    /** "Testar" das Configurações. @return array{ok: bool, erro?: string, pastas?: list<string>, mensagens?: int} */
    public function testar(string $host, int $porta, string $seguranca, string $usuario, string $senha, string $pastaEntrada): array
    {
        $c = $this->cliente();
        try {
            $c->conectar($host, $porta, $seguranca, $usuario, $senha);
            $pastas = $c->pastas();
            $info = $c->examinar($pastaEntrada);

            return ['ok' => true, 'pastas' => array_slice($pastas, 0, 100), 'mensagens' => $info['existentes']];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        } finally {
            $c->sair();
        }
    }

    /** @return array{ok: bool, novas: int, erro?: string} */
    public function sincronizar(SecretarioEmailConta $conta): array
    {
        $c = $this->cliente();
        $novas = 0;
        try {
            $c->conectar($conta->host, $conta->porta, $conta->seguranca, $conta->usuario, $conta->senha);
            $posicoes = $conta->posicoes ?? [];
            foreach (array_filter([$conta->pasta_entrada, $conta->pasta_enviados]) as $pasta) {
                $enviados = $pasta === $conta->pasta_enviados && $pasta !== $conta->pasta_entrada;
                $info = $c->examinar($pasta);
                $pos = $posicoes[$pasta] ?? null;
                $uids = ($pos && (int) $pos['uidvalidity'] === $info['uidvalidity'])
                    ? $c->depoisDe((int) $pos['ultimo_uid'])
                    : $c->desde(CarbonImmutable::now()->subDays(self::DIAS_PRIMEIRA));
                sort($uids);
                $uids = array_slice($uids, -self::MAX_POR_VEZ);
                foreach ($c->buscarMensagens($uids) as $bruto) {
                    $m = Mensagem::de($bruto);
                    $cl = $enviados ? ['tipo' => 'outro', 'urgencia' => 'normal', 'pede_retorno' => false] : $this->classificador->classificar($m, $conta->dominio());
                    try {
                        SecretarioEmail::create([
                            'user_id' => $conta->user_id, 'conta_id' => $conta->id, 'pasta' => $pasta, 'uidvalidity' => $info['uidvalidity'], 'uid' => $m->uid,
                            'message_id' => $m->messageId, 'responde_a' => $m->respondeA, 'de_nome' => $m->deNome, 'de_email' => $m->deEmail,
                            'assunto' => $m->assunto, 'recebido_em' => $m->recebidoEm, 'enviado' => $enviados,
                        ] + $cl);
                        $novas++;
                    } catch (UniqueConstraintViolationException) {
                        // Já lida (rodada concorrente ou repetição): segue.
                    }
                }
                // Etapa 8: não lidos dos últimos dias. Só a entrada; a marca é do
                // servidor e só é consultada (UNSEEN), nunca alterada.
                if (! $enviados) {
                    $desde = CarbonImmutable::now()->subDays(self::DIAS_NAO_LIDOS);
                    $naoLidos = $c->naoLidosDesde($desde);
                    $recentes = SecretarioEmail::where('conta_id', $conta->id)->where('pasta', $pasta)->where('uidvalidity', $info['uidvalidity'])
                        ->where('recebido_em', '>=', $desde->startOfDay());
                    (clone $recentes)->whereIn('uid', $naoLidos ?: [0])->update(['lido' => false]);
                    (clone $recentes)->whereNotIn('uid', $naoLidos ?: [0])->update(['lido' => true]);
                }
                // UIDVALIDITY mudou (caixa recriada): a posição antiga não vale mais.
                $base = $pos && (int) $pos['uidvalidity'] === $info['uidvalidity'] ? (int) $pos['ultimo_uid'] : 0;
                $posicoes[$pasta] = ['uidvalidity' => $info['uidvalidity'], 'ultimo_uid' => max($base, $uids ? max($uids) : 0)];
            }
            $this->marcarRespondidos($conta);
            $conta->update(['posicoes' => $posicoes, 'situacao' => 'ok', 'erro' => null, 'lida_em' => now()]);

            return ['ok' => true, 'novas' => $novas];
        } catch (RuntimeException $e) {
            $codigo = mb_substr($e->getMessage(), 0, 40);
            $conta->update(['situacao' => 'erro', 'erro' => $codigo]);
            Eventos::registrar($conta->user_id, 'secretario_email_falhou', "Caixa #{$conta->id} não foi lida ({$codigo}).", ['origem' => 'email'], 'aviso');

            return ['ok' => false, 'novas' => $novas, 'erro' => $codigo];
        } finally {
            $c->sair();
        }
    }

    /** A mensagem cuja identificação aparece como "resposta a" num enviado foi respondida. */
    public function marcarRespondidos(SecretarioEmailConta $conta): void
    {
        $respostas = SecretarioEmail::where('conta_id', $conta->id)->where('enviado', true)->whereNotNull('responde_a')->pluck('responde_a')->all();
        if ($respostas) {
            SecretarioEmail::where('conta_id', $conta->id)->where('enviado', false)->whereIn('message_id', $respostas)->update(['respondido' => true]);
        }
    }

    public function limpar(): int
    {
        return SecretarioEmail::where('recebido_em', '<', now()->subDays(self::DIAS_GUARDA))->delete();
    }

    /**
     * O começo do texto de algumas mensagens, lido NA HORA (resumo e rascunho
     * pela IA, research D5). Nada disto é guardado.
     *
     * @param  iterable<SecretarioEmail>  $emails
     * @return array<int, string> id da mensagem => texto
     */
    public function textosAgora(SecretarioEmailConta $conta, iterable $emails, int $bytes = 1500): array
    {
        $porPasta = [];
        foreach ($emails as $e) {
            $porPasta[$e->pasta][$e->uid] = $e->id;
        }
        if (! $porPasta) {
            return [];
        }
        $c = $this->cliente();
        $saida = [];
        try {
            $c->conectar($conta->host, $conta->porta, $conta->seguranca, $conta->usuario, $conta->senha);
            foreach ($porPasta as $pasta => $mapa) {
                $c->examinar($pasta);
                foreach ($c->buscarMensagens(array_keys($mapa), $bytes) as $bruto) {
                    if (isset($mapa[$bruto['uid']])) {
                        $saida[$mapa[$bruto['uid']]] = mb_substr(Mensagem::de($bruto)->texto, 0, $bytes);
                    }
                }
            }
        } catch (RuntimeException) {
            // Sem os textos, o pedido segue só com os metadados (e diz isso).
        } finally {
            $c->sair();
        }

        return $saida;
    }
}
