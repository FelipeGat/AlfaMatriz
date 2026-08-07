<?php

namespace App\Services\Integracao\Dto;

use App\Services\Integracao\ErroIntegracao;
use Carbon\CarbonImmutable;

/**
 * Um administrador do cliente, dentro do sistema.
 *
 * O contrato proíbe o sistema de mandar credencial. Este objeto descarta
 * qualquer campo desse tipo que chegue mesmo assim, para que ele nunca acabe
 * gravado no retrato local por descuido do outro lado.
 */
class UsuarioExterno
{
    /** Campos que jamais podem ser guardados, venham eles como vierem. */
    private const PROIBIDOS = ['senha', 'password', 'password_hash', 'senha_hash', 'token', 'api_key', 'secret'];

    public function __construct(
        public readonly string $idExterno,
        public readonly string $clienteIdExterno,
        public readonly string $nome,
        public readonly ?string $email,
        public readonly ?string $papel,
        public readonly bool $ativo,
        public readonly ?CarbonImmutable $ultimoAcessoEm,
        public readonly array $cru,
    ) {}

    public static function deArray(array $item): self
    {
        $id = trim((string) ($item['id_externo'] ?? ''));
        $cliente = trim((string) ($item['cliente_id_externo'] ?? ''));

        if ($id === '' || $cliente === '') {
            throw ErroIntegracao::respostaInvalida('um usuário veio sem identificador ou sem o cliente a que pertence.');
        }

        return new self(
            idExterno: $id,
            clienteIdExterno: $cliente,
            nome: trim((string) ($item['nome'] ?? '')) ?: "Usuário {$id}",
            email: $item['email'] ?? null,
            papel: $item['papel'] ?? null,
            ativo: (bool) ($item['ativo'] ?? true),
            ultimoAcessoEm: self::momento($item['ultimo_acesso_em'] ?? null),
            cru: self::semCredenciais($item),
        );
    }

    public function paraEspelho(): array
    {
        return [
            'nome' => $this->nome,
            'email' => $this->email,
            'papel' => $this->papel,
            'ativo' => $this->ativo,
            'ultimo_acesso_em' => $this->ultimoAcessoEm,
            'payload' => $this->cru,
        ];
    }

    /**
     * Tira do retrato qualquer campo que pareça credencial.
     *
     * A resposta crua é guardada inteira em todas as outras entidades, para a
     * auditoria ficar honesta. Aqui não: guardar o resumo de senha de um
     * administrador de cliente porque o sistema mandou sem querer seria
     * transformar um descuido do outro lado num vazamento permanente aqui.
     */
    private static function semCredenciais(array $item): array
    {
        foreach (array_keys($item) as $chave) {
            foreach (self::PROIBIDOS as $proibido) {
                if (str_contains(strtolower((string) $chave), $proibido)) {
                    unset($item[$chave]);
                    break;
                }
            }
        }

        return $item;
    }

    private static function momento(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
