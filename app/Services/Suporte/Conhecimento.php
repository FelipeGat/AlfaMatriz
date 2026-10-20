<?php

namespace App\Services\Suporte;

use App\Models\SuporteRoteiro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O que o Felipe ensina ao Pedro na tela Suporte › Pedro
 * (docs/specs/suporte-pedro-whatsapp/conhecimento.md): a apresentação da
 * empresa, os roteiros por situação e o ritmo da cobrança dos críticos.
 */
class Conhecimento
{
    /** Os textos da apresentação e o padrão de cada ajuste. */
    public const AJUSTES = ['apresentacao' => '', 'site' => '', 'folders' => '', 'cobranca_minutos' => '60'];

    public function ajuste(string $chave): string
    {
        $valor = DB::table('suporte_ajustes')->where('chave', $chave)->value('valor');

        return $valor !== null ? (string) $valor : (self::AJUSTES[$chave] ?? '');
    }

    public function gravarAjuste(string $chave, ?string $valor): void
    {
        DB::table('suporte_ajustes')->updateOrInsert(['chave' => $chave], ['valor' => trim((string) $valor), 'updated_at' => now(), 'created_at' => now()]);
    }

    public function cobrancaMinutos(): int
    {
        return max(15, (int) $this->ajuste('cobranca_minutos'));
    }

    /** @return \Illuminate\Support\Collection<int, SuporteRoteiro> */
    public function roteiros()
    {
        return SuporteRoteiro::where('ativo', true)->orderBy('ordem')->orderBy('id')->get();
    }

    /** A seção A EMPRESA do prompt. Link só o que está aqui: o Pedro não inventa endereço. */
    public function empresa(): string
    {
        $linhas = [];
        if (($a = $this->ajuste('apresentacao')) !== '') {
            $linhas[] = $a;
        }
        if (($s = $this->ajuste('site')) !== '') {
            $linhas[] = 'Site: '.$s;
        }
        $folders = array_values(array_filter(array_map('trim', preg_split('/\R/', $this->ajuste('folders')))));
        if ($folders) {
            $linhas[] = "Folders (envie o link que combinar com o interesse):\n".implode("\n", array_map(fn ($f) => '- '.$f, $folders));
        }

        return $linhas ? implode("\n", $linhas) : '- (sem apresentação cadastrada: não mande link nem descreva a empresa além do que aparece nesta mensagem; encaminhe a um atendente)';
    }

    /** A seção ROTEIROS do prompt. */
    public function roteirosParaOPrompt(): string
    {
        return $this->roteiros()->map(function (SuporteRoteiro $r) {
            $partes = ["- Roteiro {$r->id}: {$r->titulo}", '  - Quando usar: '.Str::squish($r->quando)];
            if (filled($r->orientacao)) {
                $partes[] = '  - O que fazer: '.str_replace("\n", "\n    ", trim($r->orientacao));
            }
            if (filled($r->mensagem)) {
                $partes[] = '  - Mensagem padrão (use este texto, adaptando só o necessário): '.str_replace("\n", "\n    ", trim($r->mensagem));
            }
            $partes[] = '  - '.($r->abre_atendimento ? 'Abre atendimento' : 'Não abre atendimento por si só')
                .($r->prioridade ? ', prioridade '.mb_strtolower(SuporteRoteiro::PRIORIDADES[$r->prioridade] ?? $r->prioridade) : '')
                .($r->prazo_horas ? ", prazo de até {$r->prazo_horas} h para atender" : '').'.';

            return implode("\n", $partes);
        })->implode("\n") ?: '- (nenhum roteiro cadastrado)';
    }
}
