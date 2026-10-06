<?php

namespace Tests\Feature\Vigia;

use App\Services\Vigia\AssinaturaDeErro;
use Tests\TestCase;

/**
 * O que faz duas linhas de log serem o MESMO erro (#219): o que varia entre
 * ocorrências sai; o que distingue um defeito de outro fica.
 */
class AssinaturaDeErroTest extends TestCase
{
    private function hash(string $mensagem, ?string $excecao = 'RuntimeException', ?string $trecho = null): string
    {
        return (new AssinaturaDeErro($mensagem, $excecao, $trecho))->hash;
    }

    public function test_numeros_ids_e_datas_diferentes_sao_a_mesma_assinatura(): void
    {
        $pares = [
            ['Pedido 4812 não encontrado', 'Pedido 977 não encontrado'],
            ['Aluno 3f2b8c1e-9a4d-4c6b-8e2f-1a2b3c4d5e6f sem plano', 'Aluno 0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d sem plano'],
            ['Falha para joao@exemplo.com', 'Falha para maria.silva@outro.com.br'],
            ['Token a94a8fe5ccb19ba61c4c0873d391e987982fbbd3 expirou', 'Token 7c4a8d09ca3762af61e59520943dc26494f8941b expirou'],
            ['Lote de 2026-10-03 14:00:01 falhou', 'Lote de 2026-09-28T09:12:44-03:00 falhou'],
            ["Check-in '8812' recusado", "Check-in '77' recusado"],
            ['Timeout após 30012ms em 10.0.0.12', 'Timeout após 512ms em 192.168.1.5'],
            // O código de ocorrência do AlfaControl: 8 hexadecimais, às vezes só dígitos.
            ['Erro interno [4cfbd08e]: Could not open JPA EntityManager for transaction', 'Erro interno [ab4da4f7]: Could not open JPA EntityManager for transaction'],
            ['Erro interno [4cfbd08e]: Could not open JPA EntityManager for transaction', 'Erro interno [12345678]: Could not open JPA EntityManager for transaction'],
        ];

        foreach ($pares as [$a, $b]) {
            $this->assertSame($this->hash($a), $this->hash($b), "«{$a}» e «{$b}» deveriam ser o mesmo erro.");
        }
    }

    public function test_o_que_distingue_defeitos_fica(): void
    {
        // Nome de coluna entre aspas não é id: são dois defeitos.
        $this->assertNotSame($this->hash("Coluna 'email' não existe"), $this->hash("Coluna 'cpf' não existe"));
        // Mesma frase, exceção diferente.
        $this->assertNotSame($this->hash('Falhou', 'QueryException'), $this->hash('Falhou', 'TypeError'));
        // Palavra só com letras de "a" a "f" não é id: fica.
        $this->assertNotSame($this->hash('Falha em deadbeef'), $this->hash('Falha em facade'));
    }

    public function test_o_quadro_da_aplicacao_entra_e_o_numero_da_linha_nao(): void
    {
        $stack = fn (string $arquivo, int $linha) => "#0 /var/www/vendor/laravel/framework/src/X.php(10): f()\n#1 /var/www/html/app/{$arquivo}({$linha}): g()";

        $this->assertSame(
            $this->hash('Falhou', 'E', $stack('Services/Wellhub.php', 87)),
            $this->hash('Falhou', 'E', $stack('Services/Wellhub.php', 91)),
            'Uma publicação que mexe a linha não pode fazer o erro velho parecer novo.'
        );
        $this->assertNotSame(
            $this->hash('Falhou', 'E', $stack('Services/Wellhub.php', 87)),
            $this->hash('Falhou', 'E', $stack('Services/Boleto.php', 87)),
        );

        $this->assertSame('app/Services/Wellhub.php', AssinaturaDeErro::primeiroQuadroDaAplicacao($stack('Services/Wellhub.php', 87)));
    }

    public function test_no_stack_java_pula_as_bibliotecas(): void
    {
        $trecho = "org.springframework.dao.InvalidDataAccessApiUsageException: No EntityManager\n"
            ."\tat org.springframework.orm.jpa.SharedEntityManagerCreator.invoke(SharedEntityManagerCreator.java:303)\n"
            ."\tat jdk.proxy2.\$Proxy142.persist(Unknown Source)\n"
            ."\tat br.com.alfa.alfagym.wellhub.WellhubService.validar(WellhubService.java:88)";

        $this->assertSame('br.com.alfa.alfagym.wellhub.WellhubService.validar', AssinaturaDeErro::primeiroQuadroDaAplicacao($trecho));
    }

    public function test_sem_stack_a_assinatura_e_classe_e_mensagem(): void
    {
        $assinatura = new AssinaturaDeErro('Deu ruim no pedido 12', '\\App\\Exceptions\\X');

        $this->assertNull($assinatura->quadro);
        $this->assertSame('App\\Exceptions\\X', $assinatura->excecao);
        $this->assertSame('Deu ruim no pedido {n}', $assinatura->padrao);
    }
}
