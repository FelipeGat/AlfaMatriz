<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * O vínculo de um cliente com um sistema — e o estado da licença dele ali.
 *
 * A regra de "vencida / vencendo / suspensa / ativa" estava escrita duas vezes
 * na Blade (na coluna e nas condições dos modais) e as duas já divergiam: uma
 * olhava `status_saas`, a outra `licenca_fim_em`. Com dois sistemas por cliente
 * a duplicação passa a ser multiplicada, então o estado vira um objeto só,
 * testável sem HTTP.
 */
class ClienteSistema extends Pivot
{
    protected $table = 'cliente_sistema';

    /** Uma licença que vence dentro deste prazo já pede atenção. */
    public const DIAS_VENCENDO = 15;

    /**
     * Sem casts de data de propósito.
     *
     * Este pivô já é lido em vários lugares que comparam `licenca_fim_em` como
     * texto. Trocar o tipo do atributo junto com a extração da regra seria
     * mudar comportamento e estrutura na mesma tacada — o jeito de transformar
     * um refactor em caça a bug. Quem precisa de data usa `fimEm()`.
     */
    public function fimEm(): ?\Illuminate\Support\Carbon
    {
        return filled($this->licenca_fim_em)
            ? \Illuminate\Support\Carbon::parse($this->licenca_fim_em)
            : null;
    }

    /**
     * O estado da licença deste cliente neste sistema.
     *
     * `bloqueia_acesso` NÃO decide nada aqui: no AlfaGym esse campo é a
     * política da licença ("bloquear ao vencer"), sempre verdadeira — usá-lo
     * marcaria todo mundo como suspenso. Quem diz o estado é `status_saas`.
     *
     * @return 'sem_licenca'|'pendente'|'suspensa'|'vencida'|'vencendo'|'ativa'
     */
    public function estado(): string
    {
        $status = (string) ($this->status_saas ?? '');

        if ($status === 'bloqueado') {
            return 'suspensa';
        }

        if ($status === 'pendente') {
            return 'pendente';
        }

        // Três origens diferentes preenchem o retrato: `/clientes` grava
        // `status_saas`, `/licencas` grava `licenca_status`, e uma liberação
        // pela Matriz grava `licenca_id_externo`. Exigir só uma delas apagaria
        // da tela o cliente que veio pelas outras.
        if (! $this->temRetrato()) {
            return 'sem_licenca';
        }

        $fim = $this->fimEm();

        if ($fim === null) {
            return 'ativa';
        }

        $hoje = now()->endOfDay();
        $fimDoDia = $fim->copy()->endOfDay();

        if ($fimDoDia->lt($hoje)) {
            return 'vencida';
        }

        return $fimDoDia->lte($hoje->copy()->addDays(self::DIAS_VENCENDO)) ? 'vencendo' : 'ativa';
    }

    /** Já existe licença emitida lá — é o que renovar e suspender precisam. */
    public function temLicenca(): bool
    {
        return filled($this->licenca_id_externo);
    }

    /**
     * O valor da licença informado pela origem, quando ela informa.
     *
     * Só conta se a licença está de pé: licença suspensa ou sem retrato não é
     * receita corrente, e somá-la infla o total da tela.
     */
    public function valorDaLicenca(): ?float
    {
        if ($this->licenca_valor === null || in_array($this->estado(), ['sem_licenca', 'suspensa'], true)) {
            return null;
        }

        return (float) $this->licenca_valor;
    }

    /** Há algum retrato de licença deste cliente neste sistema. */
    public function temRetrato(): bool
    {
        return filled($this->status_saas)
            || filled($this->licenca_status)
            || $this->temLicenca();
    }

    public function pendente(): bool
    {
        return $this->estado() === 'pendente';
    }

    public function suspensa(): bool
    {
        return $this->estado() === 'suspensa';
    }

    /** O tom do badge na tela. */
    public function tom(): string
    {
        return match ($this->estado()) {
            'suspensa' => 'critico',
            'vencida', 'vencendo' => 'atencao',
            'pendente' => 'atencao',
            'sem_licenca' => 'neutro',
            default => 'bom',
        };
    }

    public function rotulo(): string
    {
        return match ($this->estado()) {
            'suspensa' => 'suspensa',
            'vencida' => 'vencida',
            'vencendo' => 'vencendo',
            'pendente' => 'pendente',
            'sem_licenca' => 'sem licença',
            default => 'ativa',
        };
    }

    /**
     * Ordem de exibição: quem varre a lista está procurando problema, e o
     * problema não pode ficar escondido na terceira linha.
     */
    public function gravidade(): int
    {
        return match ($this->estado()) {
            'suspensa' => 0,
            'vencida' => 1,
            'vencendo' => 2,
            'pendente' => 3,
            'ativa' => 4,
            default => 5,
        };
    }
}
