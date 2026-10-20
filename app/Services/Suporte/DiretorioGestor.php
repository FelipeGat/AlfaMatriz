<?php

namespace App\Services\Suporte;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O retrato do Gestor que a máquina do Felipe manda a cada 10 min (só leitura):
 * os clientes ativos, com telefones, e os atendimentos recentes. É dele que o
 * Pedro reconhece cliente do Grupo Soluções pelo número ou pelo nome citado.
 */
class DiretorioGestor
{
    /** Palavras que não identificam ninguém ("Condomínio Residencial das …"). */
    private const VAZIAS = ['condominio', 'residencial', 'edificio', 'ed', 'cond', 'ltda', 'me', 'eireli', 'sa', 'de', 'da', 'do', 'das', 'dos', 'e',
        'empresa', 'comercio', 'servicos', 'associacao', 'the', 'parque', 'jardim', 'jardins', 'vila', 'solar', 'residence', 'res'];

    private ?array $retrato = null;

    public function gravar(string $maquina, string $lidoEm, array $clientes, array $atendimentos, array $assuntos = []): void
    {
        DB::table('suporte_gestor_retrato')->delete();
        DB::table('suporte_gestor_retrato')->insert(['maquina' => $maquina, 'lido_em' => $lidoEm, 'clientes' => json_encode(array_values($clientes), JSON_UNESCAPED_UNICODE),
            'atendimentos' => json_encode(array_values($atendimentos), JSON_UNESCAPED_UNICODE), 'assuntos' => json_encode(array_values($assuntos), JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now()]);
        $this->retrato = null;
    }

    /** @return array{lido_em: ?string, clientes: list<array>, atendimentos: list<array>} */
    public function retrato(): array
    {
        if ($this->retrato === null) {
            $r = DB::table('suporte_gestor_retrato')->orderByDesc('id')->first();
            $this->retrato = ['lido_em' => $r?->lido_em, 'clientes' => $r ? (array) json_decode($r->clientes, true) : [], 'atendimentos' => $r ? (array) json_decode($r->atendimentos, true) : [],
                'assuntos' => $r ? (array) json_decode((string) $r->assuntos, true) : []];
        }

        return $this->retrato;
    }

    public function cliente(int $id): ?array
    {
        return collect($this->retrato()['clientes'])->first(fn ($c) => (int) ($c['id'] ?? 0) === $id);
    }

    /** @return list<array> */
    public function porTelefone(string $numero): array
    {
        $chave = CanalWhatsApp::chave($numero);

        return $chave ? collect($this->retrato()['clientes'])->filter(fn ($c) => collect($c['telefones'] ?? [])->contains(fn ($t) => CanalWhatsApp::chave((string) $t) === $chave))->values()->all() : [];
    }

    /**
     * Clientes cujo nome aparece no texto: todas as palavras que identificam (fora
     * "condomínio", "residencial"…) precisam estar lá. "parque das castanheiras" acha
     * "CONDOMINIO RESIDENCIAL PARQUE DAS CASTANHEIRAS" pela palavra "castanheiras".
     *
     * @return list<array>
     */
    public function porNome(string $texto, int $limite = 3): array
    {
        $palavrasDoTexto = array_flip(self::palavras($texto));
        if (! $palavrasDoTexto) {
            return [];
        }

        return collect($this->retrato()['clientes'])
            ->filter(fn ($c) => collect([$c['fantasia'] ?? null, $c['nome'] ?? null, $c['razao'] ?? null])->contains(fn ($n) => self::citado((string) $n, $palavrasDoTexto)))
            ->take($limite)->values()->all();
    }

    /** O nome aparece no texto? Todas as palavras que identificam o nome precisam estar nele. @param array<string, int> $palavrasDoTexto */
    public static function citado(string $nome, array $palavrasDoTexto): bool
    {
        $chave = array_values(array_filter(self::palavras($nome), fn ($p) => ! in_array($p, self::VAZIAS, true) && mb_strlen($p) >= 4));

        return $chave !== [] && ! array_diff($chave, array_keys($palavrasDoTexto));
    }

    /** @return array<string, int> */
    public static function palavrasDe(string $texto): array
    {
        return array_flip(self::palavras($texto));
    }

    /**
     * Os assuntos que servem para abrir atendimento deste cliente: os de serviço da
     * empresa dele (e os sem empresa), com os que ele mais usa primeiro.
     *
     * @return list<array{id: int, nome: string, categoria: string}>
     */
    public function assuntosPara(array $cliente): array
    {
        $uso = collect($this->retrato()['atendimentos'])->filter(fn ($a) => (int) ($a['cliente_id'] ?? 0) === (int) $cliente['id'])->countBy('assunto_id');
        $empresa = $cliente['empresa_id'] ?? null;

        return collect($this->retrato()['assuntos'] ?? [])
            ->filter(fn ($a) => mb_strtoupper((string) ($a['tipo'] ?? '')) === 'SERVICO' && ($empresa === null || ($a['empresa_id'] ?? null) === null || (int) $a['empresa_id'] === (int) $empresa))
            ->sortByDesc(fn ($a) => $uso[$a['id']] ?? 0)->values()
            ->map(fn ($a) => ['id' => (int) $a['id'], 'nome' => (string) $a['nome'], 'categoria' => (string) ($a['categoria'] ?? '')])->all();
    }

    /**
     * Os e-mails de login do portal do cliente, só se o número que escreve é um
     * telefone cadastrado DELE no Gestor. @return list<string>
     */
    public function loginsDoPortal(int $clienteId, string $telefone): array
    {
        $deste = collect($this->porTelefone($telefone))->contains(fn ($c) => (int) ($c['id'] ?? 0) === $clienteId);

        return $deste ? array_values(array_filter(array_map('strval', (array) ($this->cliente($clienteId)['portal'] ?? [])))) : [];
    }

    /** O atendimento do Gestor pelo número, do retrato. */
    public function atendimentoPorNumero(string $numero): ?array
    {
        return collect($this->retrato()['atendimentos'])->first(fn ($a) => (string) ($a['numero'] ?? '') === trim($numero));
    }

    /** @return list<array> os atendimentos do cliente, mais recentes primeiro */
    public function atendimentosDe(int $clienteId, int $limite = 6): array
    {
        return collect($this->retrato()['atendimentos'])->filter(fn ($a) => (int) ($a['cliente_id'] ?? 0) === $clienteId)
            ->sortByDesc(fn ($a) => (string) ($a['criado_em'] ?? ''))->take($limite)->values()->all();
    }

    /** @return list<string> */
    private static function palavras(string $texto): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower(Str::ascii($texto))) ?: []));
    }
}
