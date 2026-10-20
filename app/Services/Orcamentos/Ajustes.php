<?php

namespace App\Services\Orcamentos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Os ajustes do módulo de orçamentos (docs/specs/orcamentos-vixcard/padrao-e-historico.md,
 * FR-H9): quem emite, os clientes que atendemos e a capa padrão. Ficam no banco e são
 * editados na tela — nenhum dado do emissor fica fixo no código.
 */
class Ajustes
{
    public const CAMPOS = [
        'emissor_nome' => 'Nome na proposta', 'emissor_razao' => 'Razão social', 'emissor_cnpj' => 'CNPJ', 'emissor_endereco' => 'Endereço',
        'emissor_cidade' => 'Cidade/UF', 'emissor_telefone' => 'Telefone', 'emissor_email' => 'E-mail', 'emissor_site' => 'Site',
    ];

    private ?array $cache = null;

    public function todos(): array
    {
        if ($this->cache === null) {
            $this->cache = Schema::hasTable('orcamento_ajustes') ? DB::table('orcamento_ajustes')->pluck('valor', 'chave')->all() : [];
        }

        return $this->cache;
    }

    public function get(string $chave, ?string $padrao = null): ?string
    {
        $v = trim((string) ($this->todos()[$chave] ?? ''));

        return $v === '' ? $padrao : $v;
    }

    public function gravar(array $valores): void
    {
        foreach ($valores as $chave => $valor) {
            DB::table('orcamento_ajustes')->updateOrInsert(['chave' => $chave], ['valor' => $valor, 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->cache = null;
    }

    /** Os clientes atendidos por setor: "## Setor" e um nome por linha. @return list<array{setor: string, nomes: list<string>}> */
    public function clientes(): array
    {
        $grupos = [];
        foreach (preg_split('/\R/', (string) $this->get('clientes', '')) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }
            if (preg_match('/^##\s*(.+)$/', $linha, $m)) {
                $grupos[] = ['setor' => trim($m[1]), 'nomes' => []];
            } else {
                if (! $grupos) {
                    $grupos[] = ['setor' => '', 'nomes' => []];
                }
                $grupos[count($grupos) - 1]['nomes'][] = $linha;
            }
        }

        return $grupos;
    }

    /** Uma linha com o que houver do emissor (rodapé e contracapa). */
    public function linhaDoEmissor(): string
    {
        return collect(['emissor_razao', 'emissor_cnpj', 'emissor_cidade', 'emissor_telefone', 'emissor_email', 'emissor_site'])
            ->map(fn ($k) => $k === 'emissor_cnpj' && $this->get($k) ? 'CNPJ '.$this->get($k) : $this->get($k))->filter()->implode(' · ');
    }
}
