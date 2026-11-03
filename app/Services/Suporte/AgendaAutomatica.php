<?php

namespace App\Services\Suporte;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * As portas da agenda automática no Gestor (docs/specs/pedro-agenda-automatica, contracts/integracao-agenda.md).
 *
 * As verificações e o envio rodam lá, com o relógio de lá (Decision 4): aqui não se guarda nada, só se lê e se pede
 * a mudança. Mesma credencial e mesmo endereço do `CanalWhatsApp` (`services.suporte_gestor`). Toda falha vira
 * ValidationException com a frase da tela: o Gestor fora do ar não pode virar erro 500 na aba, e "não respondeu" diz
 * que a configuração continua a de lá — nada aqui fica pela metade.
 */
class AgendaAutomatica
{
    public const SEM_RESPOSTA = 'O Gestor não respondeu; a configuração continua a que estava lá.';

    public const TIPOS = ['programacao' => 'Programação', 'mudanca' => 'Mudança', 'relatorio' => 'Relatório', 'alerta' => 'Alerta'];

    public const MODOS = ['desligada' => 'Desligada', 'ensaio' => 'Ensaio', 'ligada' => 'Ligada'];

    public const SITUACOES = ['preparada' => 'Preparada', 'aceita' => 'Aceita pela Meta', 'entregue' => 'Entregue', 'lida' => 'Lida',
        'falha' => 'Falha', 'ensaio' => 'Ensaio', 'sem_envio' => 'Sem envio', 'pendente' => 'Pendente'];

    public const PAPEIS = ['tecnico' => 'Técnico', 'gestor' => 'Gestor'];

    /** Os mesmos rótulos do relatório do Gestor (`Mensagens::ROTULOS_DE_PENDENCIA`): a pendência se chama igual nos dois lugares. */
    public const PENDENCIAS = [
        'inativo' => 'inativo',
        'parou' => 'pediu para parar',
        'sem_consentimento' => 'sem consentimento',
        'sem_numero' => 'sem número',
        'numero_compartilhado' => 'número compartilhado',
        'nunca_enviar' => 'número que não recebe avisos',
        'sem_modelo' => 'sem modelo aprovado',
        'falha' => 'falha de envio',
        'sem_destinatario' => 'não está na lista de destinatários',
    ];

    /** As chaves que o contrato promete em cada objeto. A que faltar aparece como "sem dado", e não como um valor estimado. */
    private const CHAVES = [
        'configuracao' => ['modo', 'manha', 'programacao', 'relatorio', 'silencio_inicio'],
        'modelo' => ['nome', 'conferido', 'aprovado', 'categoria', 'variaveis_ok'],
        'verificacao' => ['tipo', 'dia', 'modo', 'concluida_em', 'resumo', 'erro'],
        'destinatario' => ['id', 'funcionario_id', 'nome', 'papel', 'ativo', 'final', 'pendencia', 'consentimento_em', 'consentimento_por', 'parou_em'],
        'funcionario' => ['id', 'nome', 'is_tecnico', 'final', 'problema'],
        'aviso' => ['id', 'nome', 'papel', 'tipo', 'dia_agenda', 'modo', 'situacao', 'pendencia', 'canal', 'modelo', 'final', 'tentativas',
            'erro_codigo', 'erro', 'texto', 'criado_em', 'enviado_em'],
    ];

    public function configurado(): bool
    {
        return filled(config('services.suporte_gestor.url')) && filled(config('services.suporte_gestor.token'));
    }

    /** O topo da aba: configuração, envio real, os quatro modelos, as verificações de hoje e de ontem e os destinatários. */
    public function estado(): array
    {
        $r = $this->pedir('get', 'estado');

        return [
            'configuracao' => is_array($r['configuracao'] ?? null) ? $this->conferir($r['configuracao'], 'configuracao') : null,
            'envio_real' => is_bool($r['envio_real'] ?? null) ? $r['envio_real'] : null,
            'modelos' => $this->lista($r, 'modelos', 'modelo'),
            'verificacoes' => $this->lista($r, 'verificacoes', 'verificacao'),
            'destinatarios' => $this->lista($r, 'destinatarios', 'destinatario'),
        ];
    }

    /** Só funcionários ativos do Gestor: o número sai do cadastro de lá, e ninguém o digita aqui (US4, cenário 1). */
    public function funcionarios(): ?array
    {
        return $this->lista($this->pedir('get', 'funcionarios'), 'funcionarios', 'funcionario');
    }

    public function avisos(array $filtros, int $pagina): array
    {
        $r = $this->pedir('get', 'avisos', array_filter($filtros + ['pagina' => $pagina], fn ($v) => $v !== null && $v !== ''));

        return [
            'avisos' => $this->lista($r, 'avisos', 'aviso'),
            'pagina' => is_int($r['pagina'] ?? null) ? $r['pagina'] : $pagina,
            'tem_mais' => ($r['tem_mais'] ?? false) === true,
        ];
    }

    public function incluir(int $funcionarioId, string $papel, bool $consentimento, string $autor): array
    {
        $r = $this->pedir('post', 'destinatarios', ['funcionario_id' => $funcionarioId, 'papel' => $papel, 'consentimento' => $consentimento, 'autor' => $autor]);

        return $this->conferir((array) ($r['destinatario'] ?? []), 'destinatario');
    }

    /** Só o que muda: `ativo` e/ou `consentimento` (que só se registra; não se tira — quem não quer mais é desativado). */
    public function mudar(int $id, array $mudancas, string $autor): array
    {
        $r = $this->pedir('post', "destinatarios/{$id}", array_intersect_key($mudancas, array_flip(['ativo', 'consentimento'])) + ['autor' => $autor]);

        return $this->conferir((array) ($r['destinatario'] ?? []), 'destinatario');
    }

    public function configurar(array $mudancas, string $autor): array
    {
        $r = $this->pedir('post', 'configuracao', array_intersect_key($mudancas, array_flip(self::CHAVES['configuracao'])) + ['autor' => $autor]);

        return ['configuracao' => $this->conferir((array) ($r['configuracao'] ?? []), 'configuracao'), 'envio_real' => ($r['envio_real'] ?? null) === true];
    }

    /** "Prévia agora": o Gestor grava como ensaio e não chama a Meta, em qualquer modo (Decision 13). */
    public function previa(string $tipo, ?string $dia, string $autor): array
    {
        return $this->lista($this->pedir('post', 'previa', array_filter(['tipo' => $tipo, 'dia' => $dia, 'autor' => $autor])), 'avisos', 'aviso') ?? [];
    }

    /** O motivo do contrato em português, no padrão do `CanalWhatsApp::motivoDoGestor`. */
    public static function motivo(string $motivo): string
    {
        return [
            'credencial' => CanalWhatsApp::motivoDoGestor('credencial'),
            'funcionario_inexistente' => 'O Gestor não achou esse funcionário ativo: confira o cadastro de lá.',
            'destinatario_inexistente' => 'O Gestor não achou esse destinatário (a lista mudou?).',
            'horarios_invalidos' => 'Horários recusados: a manhã vem antes da programação, a programação até o relatório, e o relatório antes do silêncio.',
            'dia_invalido' => 'A prévia é só de hoje ou de amanhã.',
            'tipo_indisponivel' => 'A prévia do relatório ainda não existe no Gestor.',
        ][$motivo] ?? 'O Gestor recusou: '.$motivo;
    }

    /** Uma porta do Gestor. Devolve o JSON; qualquer falha vira ValidationException com a frase da tela. */
    private function pedir(string $metodo, string $porta, array $dados = []): array
    {
        if (! $this->configurado()) {
            throw ValidationException::withMessages(['gestor' => 'A integração com o Gestor não está configurada aqui.']);
        }
        try {
            $r = Http::withToken((string) config('services.suporte_gestor.token'))->acceptJson()->timeout(20)
                ->{$metodo}(rtrim((string) config('services.suporte_gestor.url'), '/').'/api/integracao/matriz/agenda/'.$porta, $dados);
        } catch (\Throwable $e) {
            Log::warning('[Agenda automática] o Gestor não respondeu', ['porta' => $porta, 'erro' => $e::class]);

            throw ValidationException::withMessages(['gestor' => self::SEM_RESPOSTA]);
        }
        if (! $r->successful()) {
            throw ValidationException::withMessages(['gestor' => $this->recusa($r, $porta)]);
        }
        $json = $r->json();
        if (! is_array($json)) {
            Log::warning('[Agenda automática] resposta do Gestor fora do contrato', ['porta' => $porta, 'status' => $r->status()]);

            throw ValidationException::withMessages(['gestor' => self::SEM_RESPOSTA]);
        }

        return $json;
    }

    /**
     * 404 sem motivo é a porta que não existe (Gestor ainda sem a feature), e 5xx é o Gestor caído: nos dois, nada
     * mudou lá. Validação no formato do Laravel (`errors`) mostra o primeiro erro, que é o que o Gestor disse.
     */
    private function recusa(Response $r, string $porta): string
    {
        $motivo = $r->json('motivo');
        Log::info('[Agenda automática] o Gestor recusou', ['porta' => $porta, 'status' => $r->status(), 'motivo' => is_string($motivo) ? $motivo : null]);
        if (is_string($motivo) && $motivo !== '') {
            return self::motivo($motivo);
        }
        if ($r->status() === 404 || $r->serverError()) {
            return self::SEM_RESPOSTA;
        }
        if ($r->status() === 429) {
            return 'O Gestor recebeu pedidos demais em um minuto; tente de novo daqui a pouco.';
        }
        $erros = $r->json('errors');
        if ($r->status() === 422 && is_array($erros) && $erros !== []) {
            return 'O Gestor recusou os dados: '.collect($erros)->flatten()->first();
        }

        return 'O Gestor recusou o pedido (HTTP '.$r->status().').';
    }

    /** A lista de uma chave da resposta, cada item conferido; chave ausente ou que não é lista vira null ("sem dado"). */
    private function lista(array $resposta, string $chave, string $forma): ?array
    {
        if (! array_key_exists($chave, $resposta) || ! is_array($resposta[$chave]) || ! array_is_list($resposta[$chave])) {
            Log::warning('[Agenda automática] resposta do Gestor sem a chave do contrato', ['chave' => $chave]);

            return null;
        }

        return array_map(fn ($item) => $this->conferir(is_array($item) ? $item : [], $forma), $resposta[$chave]);
    }

    /**
     * Só as chaves do contrato. As que faltarem ficam null e vão em `faltando`, para a tela dizer "sem dado" em vez
     * de ler um null como "sem número" ou "sem pendência".
     */
    private function conferir(array $item, string $forma): array
    {
        $faltando = array_values(array_diff(self::CHAVES[$forma], array_keys($item)));
        if ($faltando !== []) {
            Log::warning('[Agenda automática] resposta do Gestor sem chaves do contrato', ['forma' => $forma, 'faltando' => $faltando]);
        }

        return array_merge(array_fill_keys(self::CHAVES[$forma], null), array_intersect_key($item, array_flip(self::CHAVES[$forma])), ['faltando' => $faltando]);
    }
}
