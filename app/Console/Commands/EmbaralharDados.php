<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Troca os dados pessoais por dados falsos, para a base de staging.
 *
 * O que NÃO é tocado: valores, competências, vínculos e datas. É o volume e a
 * forma reais que fazem o staging valer para testar faturamento — o que sai
 * são nome, documento e contato de gente de verdade.
 */
class EmbaralharDados extends Command
{
    protected $signature = 'alfa:embaralhar-dados
                            {--forcar : Necessário para rodar em produção (perigoso)}
                            {--senha=staging123456 : Senha aplicada a todos os usuários}';

    protected $description = 'Troca dados pessoais por falsos (preparação da base de staging)';

    public function handle(): int
    {
        // Rodar isto em produção apaga os dados reais dos clientes. A trava é
        // deliberada: a conveniência de omiti-la não paga o risco.
        if (app()->environment('production') && ! $this->option('forcar')) {
            $this->error(
                'Recusado: este comando troca dados de cliente por dados falsos e o ambiente é production. '
                .'Rode no staging, ou repita com --forcar se é realmente isso que você quer.'
            );

            return self::FAILURE;
        }

        $senha = Hash::make($this->option('senha'));

        $this->embaralharClientes();
        $this->embaralharContatos();
        $this->embaralharFornecedores();
        $this->embaralharRevendas();
        $this->embaralharUsuarios($senha);

        $this->info('Dados pessoais embaralhados. Valores e vínculos permanecem intactos.');

        return self::SUCCESS;
    }

    private function embaralharClientes(): void
    {
        $total = 0;

        DB::table('clientes')->orderBy('id')->chunkById(200, function ($clientes) use (&$total) {
            foreach ($clientes as $cliente) {
                DB::table('clientes')->where('id', $cliente->id)->update([
                    'nome' => "Cliente {$cliente->id}",
                    'nome_fantasia' => "Cliente {$cliente->id}",
                    'razao_social' => "Cliente {$cliente->id} LTDA",
                    'cpf_cnpj' => $this->documentoFalso($cliente->id),
                    // e-mail e telefone não moram mais aqui: viraram as tabelas
                    // cliente_emails e cliente_telefones, tratadas adiante.
                    'cep' => '00000-000',
                    'logradouro' => 'Rua de Teste',
                    'numero' => (string) (100 + ($cliente->id % 900)),
                    'bairro' => 'Bairro de Teste',
                    'complemento' => null,
                    'inscricao_estadual' => null,
                    'inscricao_municipal' => null,
                    'observacoes' => null,
                ]);
                $total++;
            }
        });

        $this->line("  clientes: {$total}");
    }

    private function embaralharContatos(): void
    {
        foreach ([['cliente_emails', 'email'], ['cliente_telefones', 'telefone']] as [$tabela, $coluna]) {
            DB::table($tabela)->orderBy('id')->chunkById(200, function ($linhas) use ($tabela, $coluna) {
                foreach ($linhas as $linha) {
                    DB::table($tabela)->where('id', $linha->id)->update([
                        $coluna => $coluna === 'email'
                            ? "contato{$linha->id}@exemplo.invalido"
                            : $this->telefoneFalso($linha->id),
                    ]);
                }
            });
        }
    }

    private function embaralharFornecedores(): void
    {
        DB::table('fornecedores')->orderBy('id')->chunkById(200, function ($fornecedores) {
            foreach ($fornecedores as $f) {
                DB::table('fornecedores')->where('id', $f->id)->update([
                    'razao_social' => "Fornecedor {$f->id} LTDA",
                    'nome_fantasia' => "Fornecedor {$f->id}",
                    'cpf_cnpj' => $this->documentoFalso($f->id + 5000),
                    'email' => "fornecedor{$f->id}@exemplo.invalido",
                    'telefone' => $this->telefoneFalso($f->id + 5000),
                ]);
            }
        });
    }

    private function embaralharRevendas(): void
    {
        // O NOME da revenda fica: ele é o eixo do faturamento e não é dado
        // pessoal — é razão social de parceiro comercial da Alfa. Some só o
        // documento.
        DB::table('revendas')->whereNotNull('cnpj')->update(['cnpj' => null]);
    }

    private function embaralharUsuarios(string $senha): void
    {
        $total = 0;

        DB::table('users')->orderBy('id')->chunkById(200, function ($usuarios) use ($senha, &$total) {
            foreach ($usuarios as $usuario) {
                DB::table('users')->where('id', $usuario->id)->update([
                    'name' => "Usuario {$usuario->id}",
                    'email' => "usuario{$usuario->id}@exemplo.invalido",
                    'password' => $senha,
                ]);
                $total++;
            }
        });

        $this->line("  usuários: {$total} (senha única de teste)");
    }

    private function documentoFalso(int $semente): string
    {
        return sprintf('%02d.%03d.%03d/0001-%02d',
            $semente % 100, $semente % 1000, ($semente * 7) % 1000, $semente % 100);
    }

    private function telefoneFalso(int $semente): string
    {
        return sprintf('(27) 9%04d-%04d', $semente % 10000, ($semente * 3) % 10000);
    }
}
