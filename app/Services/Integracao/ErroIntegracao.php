<?php

namespace App\Services\Integracao;

use RuntimeException;

/**
 * O único tipo de erro que sai da camada de integração.
 *
 * Tudo que pode dar errado ao falar com um sistema — desde ele estar fora do ar
 * até ele recusar a operação — chega aqui com um código nomeado. Quem chama
 * nunca precisa distinguir exceção de rede de exceção de JSON de exceção de
 * negócio: distingue códigos.
 */
class ErroIntegracao extends RuntimeException
{
    /** Recusas do sistema, previstas no catálogo do contrato. */
    public const CATALOGO = [
        'nao_autenticado', 'nao_autorizado', 'cliente_nao_encontrado',
        'licenca_nao_encontrada', 'licenca_ja_ativa', 'plano_invalido',
        'cnpj_duplicado', 'competencia_invalida', 'operacao_nao_suportada',
        'limite_de_taxa', 'erro_interno', 'indisponivel',
    ];

    /** Problemas do lado da matriz, antes ou depois da conversa. */
    public const LOCAIS = [
        'sem_endereco', 'sem_chave', 'chave_ilegivel', 'sistema_inativo',
        'fora_do_escopo', 'conexao_falhou', 'tempo_esgotado',
        'contrato_incompativel', 'resposta_invalida',
    ];

    public function __construct(
        public readonly string $codigo,
        string $mensagem,
        public readonly ?int $httpStatus = null,
        public readonly array $detalhes = [],
    ) {
        parent::__construct($mensagem);
    }

    /**
     * Recusa é resposta do sistema dizendo "não", e repetir não muda um não.
     * É o que separa o que vale nova tentativa do que não vale.
     */
    public function ehRecusa(): bool
    {
        return $this->httpStatus !== null
            && $this->httpStatus >= 400
            && $this->httpStatus < 500
            && $this->httpStatus !== 429;
    }

    /** O sistema não está acessível agora — vale tentar de novo mais tarde. */
    public function ehIndisponibilidade(): bool
    {
        return in_array($this->codigo, ['conexao_falhou', 'tempo_esgotado', 'indisponivel', 'limite_de_taxa'], true);
    }

    /** Falta configuração aqui: tentar de novo não resolve, alguém precisa agir. */
    public function ehConfiguracao(): bool
    {
        return in_array($this->codigo, ['sem_endereco', 'sem_chave', 'chave_ilegivel', 'sistema_inativo', 'fora_do_escopo'], true);
    }

    public static function configuracao(string $motivo): self
    {
        return new self($motivo, match ($motivo) {
            'sem_endereco' => 'O sistema não tem endereço de integração configurado.',
            'sem_chave' => 'O sistema não tem chave de integração configurada.',
            'chave_ilegivel' => 'A chave de integração não pôde ser lida (a chave da aplicação mudou no servidor?).',
            'sistema_inativo' => 'O sistema está desativado no cadastro.',
            'fora_do_escopo' => 'Este produto não faz parte da integração.',
            default => 'O sistema não está configurado para integração.',
        });
    }

    public static function conexaoFalhou(string $detalhe = ''): self
    {
        return new self('conexao_falhou', trim('Não foi possível falar com o sistema. '.$detalhe));
    }

    public static function tempoEsgotado(): self
    {
        return new self('tempo_esgotado', 'O sistema demorou demais para responder.');
    }

    public static function contratoIncompativel(string $recebido, int $esperado): self
    {
        return new self(
            'contrato_incompativel',
            "O sistema respondeu na versão de contrato {$recebido}, e este painel entende a versão {$esperado}.",
            detalhes: ['recebido' => $recebido, 'esperado' => $esperado],
        );
    }

    public static function respostaInvalida(string $porque): self
    {
        return new self('resposta_invalida', "A resposta do sistema não pôde ser lida: {$porque}");
    }

    /**
     * Uma recusa que o próprio sistema declarou, no formato do contrato.
     *
     * A mensagem vem do sistema e aparece LITERALMENTE na tela. Código fora do
     * catálogo vira erro genérico: aceitar qualquer código deixaria o painel
     * tratando como conhecido algo que ele não sabe interpretar.
     */
    public static function doSistema(string $codigo, string $mensagem, int $httpStatus, array $detalhes = []): self
    {
        if (! in_array($codigo, self::CATALOGO, true)) {
            return new self(
                'erro_interno',
                $mensagem !== '' ? $mensagem : 'O sistema respondeu com um erro que este painel não conhece.',
                $httpStatus,
                ['codigo_recebido' => $codigo] + $detalhes,
            );
        }

        return new self($codigo, $mensagem, $httpStatus, $detalhes);
    }
}
