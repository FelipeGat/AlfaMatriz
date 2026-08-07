<?php

namespace App\Services\Integracao;

use App\Models\Sincronizacao;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaContador;
use App\Models\SistemaLicenca;
use App\Models\SistemaPlano;
use App\Models\SistemaRevenda;
use App\Models\SistemaUsuario;
use App\Models\User;
use App\Services\Integracao\Dto\ClienteExterno;
use App\Services\Integracao\Dto\LicencaExterna;
use App\Services\Integracao\Dto\PlanoExterno;
use App\Services\Integracao\Dto\RevendaExterna;
use App\Services\Integracao\Dto\UsuarioExterno;
use InvalidArgumentException;

/**
 * A rotina que espelha um sistema no retrato local.
 *
 * É o coração da feature de leitura: lê cada escopo do conector, grava o que
 * mudou sem duplicar (idempotência estrutural por (sistema, id na origem)) e,
 * só depois de uma varredura completa e com sucesso, marca como ausente quem
 * deixou de aparecer. Nenhum registro é apagado.
 *
 * Sistema mal configurado NÃO lança exceção: grava a execução com o motivo e
 * devolve — a tela precisa mostrar o que falta, não um rastro de pilha.
 *
 * A varredura de ausência é deliberadamente a última coisa de cada escopo: se
 * a leitura falha no meio, quem nem chegou a ser lido continua presente, e a
 * execução é registrada como parcial (AC-087).
 */
class SincronizacaoService
{
    /** Escopos lidos como lista paginada, na ordem fixa da T-068. */
    private const COLECOES = [
        'planos' => ['modelo' => SistemaPlano::class, 'dto' => PlanoExterno::class],
        'revendas' => ['modelo' => SistemaRevenda::class, 'dto' => RevendaExterna::class],
        'clientes' => ['modelo' => SistemaCliente::class, 'dto' => ClienteExterno::class],
        'usuarios' => ['modelo' => SistemaUsuario::class, 'dto' => UsuarioExterno::class],
        'licencas' => ['modelo' => SistemaLicenca::class, 'dto' => LicencaExterna::class],
    ];

    public function __construct(private readonly FabricaDeConector $fabrica) {}

    /**
     * Executa uma sincronização e registra o que aconteceu.
     *
     * @param  string  $escopo  completa|planos|revendas|clientes|usuarios|licencas|contadores
     * @param  string  $origem  agendada|manual|comando
     */
    public function sincronizar(
        Sistema $sistema,
        string $escopo = 'completa',
        string $origem = 'manual',
        ?User $disparadaPor = null,
    ): Sincronizacao {
        // Escopo desconhecido recusa ANTES de gravar qualquer coisa: um
        // registro em_andamento orfão mentiria sobre a rotina no painel.
        $escopos = $this->escoposDe($escopo);

        $execucao = Sincronizacao::create([
            'sistema_id' => $sistema->id,
            'escopo' => $escopo,
            'competencia' => $escopo === 'contadores' ? now()->format('Y-m') : null,
            'origem' => $origem,
            'disparada_por' => $disparadaPor?->id,
            'iniciada_em' => now(),
        ]);

        $motivo = $sistema->motivoIntegracaoIndisponivel();

        if ($motivo !== null) {
            return $this->finalizar($execucao, $sistema, [
                'status' => 'falha',
                'erro_codigo' => $motivo,
                'erro_mensagem' => ErroIntegracao::configuracao($motivo)->getMessage(),
            ]);
        }

        $conector = $this->fabrica->para($sistema);

        $totais = ['itens_lidos' => 0, 'itens_criados' => 0, 'itens_atualizados' => 0, 'itens_ausentes' => 0];

        try {
            foreach ($escopos as $nome) {
                if ($nome === 'contadores') {
                    $this->sincronizarContadores($conector, $sistema, now()->format('Y-m'));
                    $totais['itens_lidos'] += 1;

                    continue;
                }

                $parciais = $this->sincronizarColecao($conector, $sistema, $nome);

                foreach ($totais as $chave => $_) {
                    $totais[$chave] += $parciais[$chave];
                }
            }
        } catch (ErroIntegracao $erro) {
            // Parcial, nunca falha total: o que já tinha entrado ficou, e a
            // ausência que ia ser marcada foi adiada para a próxima varredura.
            $this->marcarFalha($sistema);

            return $this->finalizar($execucao, $sistema, $totais + [
                'status' => 'parcial',
                'erro_codigo' => $erro->codigo,
                'erro_mensagem' => $erro->getMessage(),
            ]);
        }

        $this->marcarSucesso($sistema);

        return $this->finalizar($execucao, $sistema, $totais + ['status' => 'sucesso']);
    }

    /**
     * @return array{itens_lidos: int, itens_criados: int, itens_atualizados: int, itens_ausentes: int}
     */
    private function sincronizarColecao(ConectorSistema $conector, Sistema $sistema, string $escopo): array
    {
        ['modelo' => $modelo, 'dto' => $dto] = self::COLECOES[$escopo];

        $lidos = 0;
        $criados = 0;
        $atualizados = 0;
        $presentes = [];
        $pagina = 1;

        do {
            $resposta = match ($escopo) {
                'planos' => $conector->planos($pagina),
                'revendas' => $conector->revendas($pagina),
                'clientes' => $conector->clientes($pagina),
                'usuarios' => $conector->usuarios($pagina),
                'licencas' => $conector->licencas($pagina),
            };

            foreach ($resposta->itens() as $item) {
                $externo = $dto::deArray($item);
                $lidos++;

                $criou = $this->gravar($modelo, $sistema, $externo, $escopo);

                if ($criou === true) {
                    $criados++;
                } elseif ($criou === false) {
                    $atualizados++;
                }

                $presentes[] = $externo->idExterno;
            }

            $pagina++;
        } while ($resposta->temProximaPagina());

        return [
            'itens_lidos' => $lidos,
            'itens_criados' => $criados,
            'itens_atualizados' => $atualizados,
            'itens_ausentes' => $this->marcarAusentes($modelo, $sistema, $presentes),
        ];
    }

    /**
     * Grava um item do sistema no retrato local.
     *
     * true = criado, false = atualizado, null = ignorado. Ignorar acontece
     * quando o item depende de outro que ainda não entrou (ex.: licença de um
     * cliente que não foi sincronizado): pendurar órfão não é possível, porque
     * o banco exige o vínculo.
     */
    private function gravar(string $modelo, Sistema $sistema, object $externo, string $escopo): ?bool
    {
        $valores = $externo->paraEspelho();

        switch ($escopo) {
            case 'clientes':
                $valores['sistema_revenda_id'] = $this->revendaLocal($sistema, $externo->revendaIdExterno);

                break;

            case 'usuarios':
            case 'licencas':
                $cliente = $this->clienteLocal($sistema, $externo->clienteIdExterno);

                if ($cliente === null) {
                    return null;
                }

                $valores['sistema_cliente_id'] = $cliente;

                break;
        }

        $valores['ausente_em_origem_em'] = null;
        $valores['sincronizado_em'] = now();

        $existe = $modelo::query()
            ->where('sistema_id', $sistema->id)
            ->where('id_externo', $externo->idExterno)
            ->exists();

        $modelo::updateOrCreate(
            ['sistema_id' => $sistema->id, 'id_externo' => $externo->idExterno],
            $valores,
        );

        return ! $existe;
    }

    /**
     * Marca como ausente quem não apareceu na varredura.
     *
     * Só é chamado com a varredura do escopo COMPLETA e sem erro — é a linha
     * que impede uma falha na terceira página de desativar a base inteira.
     * Lista vazia significa que o sistema realmente não tem nenhum: aí todos
     * os que existiam são marcados.
     */
    private function marcarAusentes(string $modelo, Sistema $sistema, array $presentes): int
    {
        $consulta = $modelo::doSistema($sistema)->presentes();

        if ($presentes !== []) {
            $consulta->whereNotIn('id_externo', $presentes);
        }

        $ausentes = 0;

        $consulta->get()->each(function ($registro) use (&$ausentes) {
            $registro->marcarAusenteNaOrigem();
            $ausentes++;
        });

        return $ausentes;
    }

    private function sincronizarContadores(ConectorSistema $conector, Sistema $sistema, string $competencia): void
    {
        $contadores = $conector->contadores($competencia);

        SistemaContador::updateOrCreate(
            ['sistema_id' => $sistema->id, 'competencia' => $competencia],
            $contadores->paraEspelho(),
        );
    }

    private function revendaLocal(Sistema $sistema, ?string $idExterno): ?int
    {
        if ($idExterno === null) {
            return null;
        }

        return SistemaRevenda::doSistema($sistema)->where('id_externo', $idExterno)->value('id');
    }

    private function clienteLocal(Sistema $sistema, string $idExterno): ?int
    {
        return SistemaCliente::doSistema($sistema)->where('id_externo', $idExterno)->value('id');
    }

    /** @return list<string> */
    private function escoposDe(string $escopo): array
    {
        return match ($escopo) {
            'completa' => ['planos', 'revendas', 'clientes', 'usuarios', 'licencas', 'contadores'],
            'planos', 'revendas', 'clientes', 'usuarios', 'licencas', 'contadores' => [$escopo],
            default => throw new InvalidArgumentException("Escopo de sincronização desconhecido: {$escopo}."),
        };
    }

    private function marcarSucesso(Sistema $sistema): void
    {
        $sistema->forceFill([
            'sincronizado_em' => now(),
            'falhas_consecutivas' => 0,
        ])->save();
    }

    private function marcarFalha(Sistema $sistema): void
    {
        $sistema->forceFill([
            'falhas_consecutivas' => $sistema->falhas_consecutivas + 1,
        ])->save();
    }

    private function finalizar(Sincronizacao $execucao, Sistema $sistema, array $campos): Sincronizacao
    {
        $execucao->forceFill($campos + [
            'finalizada_em' => now(),
            'duracao_ms' => (int) max(0, now()->diffInMilliseconds($execucao->iniciada_em)),
        ])->save();

        return $execucao;
    }
}
