<?php

namespace App\Console\Commands;

use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaLicenca;
use App\Models\SistemaPlano;
use App\Models\SistemaRevenda;
use App\Services\Integracao\Documento;
use Illuminate\Console\Command;

/**
 * Enche o retrato local com dados de exemplo, para as telas de integração
 * poderem ser vistas antes de a sincronização existir.
 *
 * Existe porque as telas foram feitas antes do sincronizador: sem isto, quem
 * abrisse a seção Integração no staging veria seis telas vazias e não teria o
 * que revisar.
 *
 * RECUSA rodar em produção. Dado de exemplo no retrato viraria divergência
 * falsa na tela e, pior, cliente inventado na conferência do corte.
 */
class RetratoDeDemonstracao extends Command
{
    protected $signature = 'app:retrato-de-demonstracao
                            {--sistema= : Slug do sistema (padrão: o primeiro produto vendido como serviço)}
                            {--limpar : Apaga o retrato de exemplo em vez de criar}';

    protected $description = 'Enche o retrato local com dados de exemplo (fora de produção)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando não roda em produção: dado de exemplo no retrato viraria divergência falsa.');

            return self::FAILURE;
        }

        $sistema = $this->sistema();

        if (! $sistema) {
            $this->error('Nenhum produto vendido como serviço encontrado.');

            return self::FAILURE;
        }

        if ($this->option('limpar')) {
            SistemaCliente::doSistema($sistema)->delete();
            SistemaRevenda::doSistema($sistema)->delete();
            SistemaPlano::doSistema($sistema)->delete();
            $sistema->forceFill(['sincronizado_em' => null, 'importado_em' => null])->save();

            $this->info("Retrato de exemplo do {$sistema->nome} apagado.");

            return self::SUCCESS;
        }

        $amostras = base_path('tests/Fixtures/Integracao/v1');

        if (! is_dir($amostras)) {
            $this->error('As amostras não foram encontradas em tests/Fixtures/Integracao/v1.');

            return self::FAILURE;
        }

        $revendas = $this->carregar($amostras, 'revendas');
        $clientes = $this->carregar($amostras, 'clientes');
        $planos = $this->carregar($amostras, 'planos');
        $licencas = $this->carregar($amostras, 'licencas');

        foreach ($planos as $item) {
            SistemaPlano::updateOrCreate(
                ['sistema_id' => $sistema->id, 'id_externo' => (string) $item['id_externo']],
                ['nome' => $item['nome'], 'ativo' => true, 'sincronizado_em' => now()],
            );
        }

        $porIdExternoDaRevenda = [];
        foreach ($revendas as $item) {
            $registro = SistemaRevenda::updateOrCreate(
                ['sistema_id' => $sistema->id, 'id_externo' => (string) $item['id_externo']],
                [
                    'nome' => $item['nome'],
                    'cnpj' => Documento::normalizar($item['cnpj'] ?? null),
                    'ativo' => (bool) ($item['ativo'] ?? true),
                    'clientes_ativos' => (int) ($item['clientes_ativos'] ?? 0),
                    'sincronizado_em' => now(),
                ],
            );
            $porIdExternoDaRevenda[(string) $item['id_externo']] = $registro->id;
        }

        $porIdExternoDoCliente = [];
        foreach ($clientes as $item) {
            $registro = SistemaCliente::updateOrCreate(
                ['sistema_id' => $sistema->id, 'id_externo' => (string) $item['id_externo']],
                [
                    'sistema_revenda_id' => $porIdExternoDaRevenda[(string) ($item['revenda_id_externo'] ?? '')] ?? null,
                    'nome' => $item['nome'],
                    'razao_social' => $item['razao_social'] ?? null,
                    'cpf_cnpj' => Documento::normalizar($item['cpf_cnpj'] ?? null),
                    'cidade' => $item['cidade'] ?? null,
                    'uf' => $item['uf'] ?? null,
                    'ativo' => (bool) ($item['ativo'] ?? true),
                    'status' => $item['status'] ?? 'ativo',
                    'revenda_id_externo' => (string) ($item['revenda_id_externo'] ?? '') ?: null,
                    'unidades_ativas' => (int) ($item['unidades_ativas'] ?? 0),
                    'sincronizado_em' => now(),
                ],
            );
            $porIdExternoDoCliente[(string) $item['id_externo']] = $registro->id;
        }

        foreach ($licencas as $item) {
            $clienteId = $porIdExternoDoCliente[(string) ($item['cliente_id_externo'] ?? '')] ?? null;

            if (! $clienteId) {
                continue;
            }

            SistemaLicenca::updateOrCreate(
                ['sistema_id' => $sistema->id, 'id_externo' => (string) $item['id_externo']],
                [
                    'sistema_cliente_id' => $clienteId,
                    'status' => $item['status'] ?? 'pendente',
                    'plano' => $item['plano'] ?? null,
                    'tipo' => $item['tipo'] ?? null,
                    'inicio_em' => $item['inicio_em'] ?? null,
                    'fim_em' => $item['fim_em'] ?? null,
                    'bloqueia_acesso' => (bool) ($item['bloqueia_acesso'] ?? false),
                    'sincronizado_em' => now(),
                ],
            );
        }

        $sistema->forceFill(['sincronizado_em' => now(), 'importado_em' => now()])->save();

        $this->info("Retrato de exemplo criado para {$sistema->nome}:");
        $this->line('  revendas .. '.count($revendas));
        $this->line('  clientes .. '.count($clientes));
        $this->line('  planos .... '.count($planos));
        $this->line('  licenças .. '.count($licencas));
        $this->newLine();
        $this->line('Abra a seção Integração no painel. Para desfazer:');
        $this->line('  php artisan app:retrato-de-demonstracao --limpar --sistema='.$sistema->slug);

        return self::SUCCESS;
    }

    private function sistema(): ?Sistema
    {
        $consulta = Sistema::where('categoria', 'saas');

        if ($slug = $this->option('sistema')) {
            return $consulta->where('slug', $slug)->first();
        }

        return $consulta->orderBy('nome')->first();
    }

    private function carregar(string $pasta, string $escopo): array
    {
        $caminho = "{$pasta}/{$escopo}.json";

        return is_file($caminho) ? (json_decode(file_get_contents($caminho), true) ?? []) : [];
    }
}
