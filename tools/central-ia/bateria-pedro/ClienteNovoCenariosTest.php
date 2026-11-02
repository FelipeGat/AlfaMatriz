<?php

namespace Tests\Bateria;

use App\Models\SuporteConversa;
use App\Services\Suporte\Contexto;
use App\Services\Suporte\DiretorioGestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A bateria da busca do cliente novo (02/11/2026: o "Duque de Caxias" que o Pedro não achou, e as duas "Fit
 * Club" que pedem o CNPJ). Monta os pedidos com o Contexto de verdade para a IA de verdade responder
 * (ponta-a-ponta/rodar-ia.mjs). Fora das suítes do phpunit.xml; roda pelo ponta-a-ponta/rodar-php.py:
 *
 *   python3 tools/central-ia/bateria-pedro/ponta-a-ponta/rodar-php.py pedidos <pasta> "" tools/central-ia/bateria-pedro/ClienteNovoCenariosTest.php
 *
 * Nenhum nome, telefone ou CNPJ aqui é de cliente real (os CNPJ só têm o dígito verificador certo).
 */
class ClienteNovoCenariosTest extends TestCase
{
    use RefreshDatabase;

    private const DESCONHECIDO = '5527988887777';

    public function test_monta_os_pedidos(): void
    {
        if (getenv('BATERIA_FASE') !== 'pedidos') {
            $this->markTestSkipped('BATERIA_FASE=pedidos');
        }
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'America/Sao_Paulo'));
        $cliente = fn (int $id, string $nome, string $cidade, array $extra = []) => $extra + ['id' => $id, 'nome' => $nome, 'fantasia' => $nome, 'razao' => '',
            'cidade' => $cidade, 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => []];
        app(DiretorioGestor::class)->gravar('wsl', now()->toDateTimeString(), [
            $cliente(501, 'XPT COLEGIO BARAO DE MAUA', 'SANTA LEOPOLDINA', ['documento' => '77888999000181']),
            $cliente(502, 'CONDOMINIO RESIDENCIAL MAUA', 'MARATAIZES'),
            $cliente(601, 'POWER GYM', 'VITORIA', ['razao' => 'POWER GYM ACADEMIA LTDA', 'documento' => '11222333000181']),
            $cliente(602, 'ACADEMIA POWER GYM (CARIACICA)', 'CARIACICA', ['documento' => '44.555.666/0001-81']),
            $cliente(701, 'ZULEICA MORAES', 'SERRA'),
            $cliente(702, 'RESIDENCIAL ATLANTICO', 'GUARAPARI'),
            $cliente(703, 'PADARIA MORAES', 'SERRA'),
            $cliente(704, 'MORAES ENGENHARIA', 'SERRA'),
        ], [], [['id' => 3, 'nome' => 'PORTÃO / ACESSO', 'tipo' => 'SERVICO', 'categoria' => 'CONTROLE DE ACESSO', 'empresa_id' => 3]]);

        $pedidos = [];
        foreach ($this->cenarios() as $n => $s) {
            SuporteConversa::query()->delete();
            $c = SuporteConversa::create(['telefone' => self::DESCONHECIDO, 'nome_perfil' => 'Joaquim', 'ultima_entrada_em' => now()]);
            foreach ($s['mensagens'] as $i => [$direcao, $texto]) {
                $c->mensagens()->create(['wamid' => "wamid.cn{$n}.{$i}", 'direcao' => $direcao, 'tipo' => 'text', 'texto' => $texto,
                    'enviada_em' => now()->subMinutes(count($s['mensagens']) - $i)]);
            }
            $pedidos[] = ['n' => $n + 1, 'nome' => $s['nome'], 'esperado' => $s['esperado'], 'proibido' => $s['proibido'],
                'conversa' => array_map(fn ($m) => ['de' => $m[0] === 'entrada' ? 'pessoa' : 'pedro', 'texto' => $m[1]], $s['mensagens']),
                'prompt' => app(Contexto::class)->prompt($c->fresh())];
        }
        file_put_contents('/bateria/pedidos.json', json_encode($pedidos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->assertCount(count($this->cenarios()), $pedidos);
    }

    /** @return list<array{nome: string, esperado: string, proibido: list<string>, mensagens: list<array{0: string, 1: string}>}> */
    private function cenarios(): array
    {
        $cidades = ['Santa Leopoldina', 'Marataízes', 'Vitória', 'Cariacica', 'Serra', 'Guarapari'];
        $pedeCnpj = ['entrada', 'oi, aqui é da Power Gym, a câmera da recepção apagou'];
        $pedroPediu = ['pedro', 'Oi! Sou o Pedro, do suporte. Para localizar o seu cadastro, pode me passar o CNPJ da academia?'];

        return [
            ['nome' => 'nome curto, um cadastro só', 'esperado' => 'pergunta se é o XPT Colégio Barão de Mauá, só o nome; cliente null',
                'proibido' => [...$cidades, 'Residencial Mauá', 'CONDOMINIO'], 'mensagens' => [['entrada', 'boa tarde, sou do Barão de Mauá, o portão da escola não abre']]],
            ['nome' => 'disse "não" ao candidato', 'esperado' => 'pede o nome da empresa ou do condomínio, sem citar outro cliente; cliente null',
                'proibido' => [...$cidades, 'Residencial Mauá', 'CONDOMINIO'], 'mensagens' => [['entrada', 'boa tarde, sou do Barão de Mauá, o portão da escola não abre'],
                    ['pedro', 'Oi! Sou o Pedro, do suporte. É do XPT Colégio Barão de Mauá?'], ['entrada', 'não, não é esse']]],
            ['nome' => 'dois de nome parecido', 'esperado' => 'pede o CNPJ, sem nome, cidade nem "mais de uma"; cliente null',
                'proibido' => [...$cidades, 'ACADEMIA POWER', 'duas', 'mais de uma', 'mais de um', 'unidade'], 'mensagens' => [$pedeCnpj]],
            ['nome' => 'CNPJ que não confere', 'esperado' => 'não localizou por esse CNPJ, pede para conferir; nada confirmado nem dica de qual',
                'proibido' => [...$cidades, 'duas', 'mais de uma', 'unidade', 'parecid'], 'mensagens' => [$pedeCnpj, $pedroPediu, ['entrada', '77.888.999/0001-81']]],
            ['nome' => 'CNPJ que confere', 'esperado' => 'confirma o gestor:602 e pede o nome da pessoa',
                'proibido' => ['Vitória', 'VITORIA', 'duas', 'outra unidade'], 'mensagens' => [$pedeCnpj, $pedroPediu, ['entrada', 'é 44.555.666/0001-81']]],
            ['nome' => 'sem o CNPJ', 'esperado' => 'acao transferir; sem citar cliente',
                'proibido' => [...$cidades, 'duas', 'mais de uma', 'unidade'], 'mensagens' => [$pedeCnpj, $pedroPediu, ['entrada', 'não tenho o CNPJ aqui agora, sou só o recepcionista']]],
            ['nome' => 'nome da pessoa igual ao de um cliente', 'esperado' => 'Residencial Atlântico confirmado (gestor:702) e solicitante Zuleica; nada da ZULEICA MORAES',
                'proibido' => ['Moraes', 'MORAES', 'Serra'], 'mensagens' => [['entrada', 'bom dia, sou a Zuleica, do Residencial Atlântico, o interfone não funciona']]],
        ];
    }
}
