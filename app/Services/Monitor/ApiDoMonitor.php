<?php

namespace App\Services\Monitor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A API de leitura do AlfaMonitor (#394): `GET /api/v1/…`, uma rota por tela.
 *
 * As telas do Monitor no Matriz SÓ MOSTRAM — decisão do dono em 17/10/2026.
 * Reiniciar VPS, sino e cadastros ficam no monitor, e por isso o token é de
 * leitura e esta classe só faz GET.
 *
 * Três regras, todas pelo mesmo motivo — a tela não pode depender de o
 * monitor estar de pé no segundo em que alguém a abre:
 *
 * - CACHE por rota e parâmetros. O monitor responde do retrato em memória
 *   (barato), mas cada aba aberta atualiza a cada 30 s, e dez abas não
 *   precisam de dez consultas. O relatório de 7 e 30 dias custa ~8 s lá
 *   quando o cache dele vence; aqui ele fica 15 min.
 * - ÚLTIMO DADO BOM. Falhou (rede, 503 de retrato velho, 500)? A tela recebe
 *   o último retrato que deu certo, marcado `velho`, com a hora DELE. "Sem
 *   dado" só quando nunca houve um.
 * - GERAÇÃO. O webhook do monitor chama `invalidar()` a cada alerta, e o
 *   número da geração entra na chave do cache: o alerta aparece na próxima
 *   atualização da tela, sem esperar o prazo. O último bom não leva a
 *   geração — ele é a rede de segurança, não o cache.
 *
 * Não usa cron: só consulta quem está com a tela aberta. Uma rotina de
 * fundo consultaria o monitor a noite inteira para ninguém.
 */
class ApiDoMonitor
{
    public const VERSAO_DO_CONTRATO = 1;

    /** Telas vivas: o retrato do monitor muda a cada 5 s, a tela a cada 30. */
    public const CACHE_VIVO_S = 30;

    /** Relatório de 7 e 30 dias: conta pesada lá, número que muda devagar. */
    public const CACHE_RELATORIO_S = 15 * 60;

    /** Por quanto tempo o último retrato bom ainda vale como "velho". */
    public const ULTIMO_BOM_S = 24 * 60 * 60;

    public const TIMEOUT_S = 5;

    /** O relatório que venceu lá leva ~8 s: 5 s cortariam a resposta certa. */
    public const TIMEOUT_RELATORIO_S = 20;

    /**
     * A API pagina as listas desde 17/10/2026 (pedido do dono, antes da tag):
     * 50 por página se ninguém disser nada. O Matriz pede o máximo do
     * contrato e junta as páginas, porque a busca, a ordem e o agrupamento
     * por cliente das telas são feitos AQUI — sobre uma página só, a busca
     * não acharia o cliente da página 3 e "Por cliente" contaria errado. A
     * tela pagina depois, sobre a lista inteira (`PainelDoMonitorController`).
     */
    public const POR_PAGINA = 200;

    /** Quantas vezes a lista é buscada de novo se mudar no meio (#399). */
    private const TENTATIVAS_DA_LISTA = 3;

    /** Trava contra lista que não acaba: 50 × 200 já são 10 mil itens. */
    private const PAGINAS_MAX = 50;

    private const PREFIXO = 'alfamonitor-api:';

    public function configurada(): bool
    {
        return filled(config('services.alfamonitor.api_url')) && filled(config('services.alfamonitor.api_token'));
    }

    /**
     * @param  array<string, scalar|null>  $parametros
     */
    public function buscar(string $rota, array $parametros = [], int $cacheS = self::CACHE_VIVO_S, int $timeoutS = self::TIMEOUT_S): RespostaDoMonitor
    {
        if (! $this->configurada()) {
            return RespostaDoMonitor::falha('O Matriz ainda não tem o endereço e o token de leitura do AlfaMonitor (ALFAMONITOR_API_URL e ALFAMONITOR_API_TOKEN).');
        }

        $parametros = array_filter($parametros, fn ($v) => $v !== null && $v !== '');
        ksort($parametros);
        $assinatura = sha1(trim($rota, '/').'?'.http_build_query($parametros));
        $chave = self::PREFIXO.$this->geracao().':'.$assinatura;
        $chaveDoBom = self::PREFIXO.'bom:'.$assinatura;

        $guardado = Cache::get($chave);

        if (is_array($guardado)) {
            return $this->resposta($guardado);
        }

        $paginada = $this->listaPaginada($rota) !== null;
        $pedido = $paginada ? $parametros + ['por_pagina' => self::POR_PAGINA] : $parametros;

        try {
            $http = $this->pedir($rota, $pedido, $timeoutS);
        } catch (ConnectionException) {
            return $this->ultimoBom($chaveDoBom, 'O AlfaMonitor não respondeu.');
        } catch (Throwable $e) {
            Log::warning('AlfaMonitor: falha ao consultar a API de leitura.', ['rota' => $rota, 'erro' => $e->getMessage()]);

            return $this->ultimoBom($chaveDoBom, 'O AlfaMonitor não respondeu.');
        }

        if ($http->status() === 404) {
            return RespostaDoMonitor::inexistente();
        }

        if ($http->status() === 401 || $http->status() === 403) {
            // Token errado não se resolve sozinho: log para quem administra,
            // e a tela segue com o último dado, dizendo o porquê.
            Log::warning('AlfaMonitor: o token de leitura foi recusado.', ['rota' => $rota, 'status' => $http->status()]);

            return $this->ultimoBom($chaveDoBom, 'O AlfaMonitor recusou o token de leitura do Matriz.');
        }

        $dados = $http->json();

        if (! $http->successful() || ! is_array($dados)) {
            $motivo = ($dados['erro'] ?? null) === 'retrato_indisponivel'
                ? 'O AlfaMonitor está sem retrato recente (a coleta dele parou).'
                : 'O AlfaMonitor respondeu com erro ('.$http->status().').';

            return $this->ultimoBom($chaveDoBom, $motivo);
        }

        if (($dados['v'] ?? null) !== self::VERSAO_DO_CONTRATO) {
            // Contrato novo antes deste código: mostrar campo com nome mudado
            // como se fosse zero seria pior que não mostrar.
            Log::warning('AlfaMonitor: resposta com versão de contrato desconhecida.', ['rota' => $rota, 'v' => $dados['v'] ?? null]);

            return $this->ultimoBom($chaveDoBom, 'O AlfaMonitor mudou o formato da resposta; o Matriz precisa ser atualizado.');
        }

        if ($paginada) {
            // Lista pela metade parece lista inteira: melhor o último retrato
            // completo, marcado velho, do que uma tela que esconde o resto.
            try {
                $dados = $this->listaInteira($rota, $pedido, $timeoutS, $dados);
            } catch (ListaMudouDuranteABusca) {
                return $this->ultimoBom($chaveDoBom, 'A lista do AlfaMonitor mudou durante a busca; tente de novo em instantes.');
            } catch (Throwable $e) {
                Log::warning('AlfaMonitor: falha ao buscar as páginas seguintes.', ['rota' => $rota, 'erro' => $e->getMessage()]);

                return $this->ultimoBom($chaveDoBom, 'O AlfaMonitor não entregou a lista inteira.');
            }
        }

        Cache::put($chave, $dados, $cacheS);
        Cache::put($chaveDoBom, $dados, self::ULTIMO_BOM_S);

        return $this->resposta($dados);
    }

    /**
     * O CSV do relatório, repassado como veio: o monitor já o monta, e
     * remontá-lo aqui seria manter duas versões da mesma planilha. Sem cache
     * — exportar é gesto de uma pessoa, não atualização de tela.
     */
    public function exportarRelatorio(string $janela): ?string
    {
        if (! $this->configurada()) {
            return null;
        }

        try {
            $http = $this->pedir('relatorio/exportar', ['janela' => $janela, 'formato' => 'csv'], self::TIMEOUT_RELATORIO_S, 'text/csv');
        } catch (Throwable) {
            return null;
        }

        return $http->successful() ? $http->body() : null;
    }

    /**
     * Onde mora a lista paginada de cada rota, pelo contrato. `acesso`
     * pagina os clientes DENTRO de cada fonte, com um `paginacao` por fonte;
     * as outras levam um `paginacao` ao lado da lista. Rota fora daqui não
     * pagina (visão geral, Hostinger, servidor e relatório são curtas).
     */
    private function listaPaginada(string $rota): ?string
    {
        $rota = trim($rota, '/');

        return match (true) {
            $rota === 'incidentes' => 'incidentes',
            $rota === 'clientes' => 'clientes',
            $rota === 'acesso' => 'fontes',
            str_starts_with($rota, 'clientes/') => 'quedas_lista_7d',
            default => null,
        };
    }

    /**
     * A lista inteira, recomeçando do zero se ela MUDAR no meio da busca
     * (#399). A paginação do monitor é por número de página sobre uma lista
     * viva: um incidente novo entra no topo e empurra todo mundo uma posição,
     * e a página 2 repete o último da 1 — ou, se um sai, pula um. O `total`
     * de cada página denuncia isso; aí as páginas já juntadas não valem e a
     * busca recomeça, até `TENTATIVAS_DA_LISTA` vezes.
     *
     * @param  array<string, scalar>  $pedido
     * @param  array<string, mixed>  $primeira
     * @return array<string, mixed>
     *
     * @throws ListaMudouDuranteABusca se mudou em todas as tentativas
     */
    private function listaInteira(string $rota, array $pedido, int $timeoutS, array $primeira): array
    {
        for ($tentativa = 1; ; $tentativa++) {
            try {
                return $this->juntarPaginas($rota, $pedido, $timeoutS, $primeira);
            } catch (ListaMudouDuranteABusca $e) {
                if ($tentativa >= self::TENTATIVAS_DA_LISTA) {
                    Log::warning('AlfaMonitor: a lista mudou durante a busca em todas as tentativas.', ['rota' => $rota, 'tentativas' => $tentativa]);

                    throw $e;
                }

                $primeira = $this->pagina($rota, $pedido, $timeoutS);
            }
        }
    }

    /**
     * Pede as páginas 2 em diante e acrescenta cada uma à primeira. Monitor
     * que ainda não pagina não manda `paginacao`, e aí a primeira já é a
     * lista inteira.
     *
     * @param  array<string, scalar>  $pedido
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     *
     * @throws ListaMudouDuranteABusca se o total de uma página não bate com o da primeira
     * @throws RuntimeException se uma página falhar
     */
    private function juntarPaginas(string $rota, array $pedido, int $timeoutS, array $dados): array
    {
        $lista = $this->listaPaginada($rota);
        $porFonte = $lista === 'fontes';
        // Em `acesso` cada fonte pagina à parte, então o total é por fonte.
        $totais = fn (array $d) => $porFonte
            ? collect($d['fontes'] ?? [])->filter(fn ($f) => is_array($f))
                ->mapWithKeys(fn ($f) => [(string) ($f['fonte'] ?? '') => $f['paginacao']['total'] ?? null])->sortKeys()->all()
            : ($d['paginacao']['total'] ?? null);
        $totalDaPrimeira = $totais($dados);
        $paginasDe = fn (array $bloco): int => max(1, (int) ($bloco['paginacao']['paginas'] ?? 1));

        $paginas = $porFonte
            ? (int) collect($dados['fontes'] ?? [])->map(fn ($f) => is_array($f) ? $paginasDe($f) : 1)->max()
            : $paginasDe($dados);

        if ($paginas > self::PAGINAS_MAX) {
            Log::warning('AlfaMonitor: lista com mais páginas que a trava; o resto fica de fora.', ['rota' => $rota, 'paginas' => $paginas]);
            $paginas = self::PAGINAS_MAX;
        }

        for ($pagina = 2; $pagina <= $paginas; $pagina++) {
            $proxima = $this->pagina($rota, $pedido + ['pagina' => $pagina], $timeoutS);

            // Por fonte, só conta quem veio nas duas: a fonte com menos
            // páginas pode faltar na página seguinte sem a lista ter mudado.
            $mudou = $porFonte
                ? array_intersect_key($totais($proxima), $totalDaPrimeira) !== array_intersect_key($totalDaPrimeira, $totais($proxima))
                : $totais($proxima) !== $totalDaPrimeira;

            if ($mudou) {
                throw new ListaMudouDuranteABusca($rota);
            }

            if ($porFonte) {
                $seguintes = collect($proxima['fontes'] ?? [])->filter(fn ($f) => is_array($f))->keyBy('fonte');

                foreach ($dados['fontes'] ?? [] as $i => $fonte) {
                    $mais = $seguintes->get($fonte['fonte'] ?? null)['clientes'] ?? [];
                    $dados['fontes'][$i]['clientes'] = array_merge($fonte['clientes'] ?? [], $mais);
                }
            } else {
                $dados[$lista] = array_merge($dados[$lista] ?? [], $proxima[$lista] ?? []);
            }
        }

        // Juntas, viram uma página só: quem ler `paginacao` depois não deve
        // achar que falta alguma.
        $achatar = fn (array $bloco, int $quantos): array => isset($bloco['paginacao'])
            ? ['paginacao' => ['pagina' => 1, 'por_pagina' => max(1, $quantos), 'paginas' => 1] + $bloco['paginacao']] + $bloco
            : $bloco;

        if ($porFonte) {
            foreach ($dados['fontes'] ?? [] as $i => $fonte) {
                $dados['fontes'][$i] = $achatar($fonte, count($fonte['clientes'] ?? []));
            }

            return $dados;
        }

        return $achatar($dados, count($dados[$lista] ?? []));
    }

    /**
     * Uma página de lista, sem cache, já conferida.
     *
     * @param  array<string, scalar>  $pedido
     * @return array<string, mixed>
     *
     * @throws RuntimeException se a resposta não serve
     */
    private function pagina(string $rota, array $pedido, int $timeoutS): array
    {
        $http = $this->pedir($rota, $pedido, $timeoutS);
        $dados = $http->json();

        if (! $http->successful() || ! is_array($dados) || ($dados['v'] ?? null) !== self::VERSAO_DO_CONTRATO) {
            throw new RuntimeException("Página inválida de {$rota} (HTTP {$http->status()}).");
        }

        return $dados;
    }

    /**
     * As notificações que o monitor mandou (ou tentou mandar) pelo webhook,
     * depois do `event_id` dado, em ordem crescente (#399). É a fonte da
     * reconciliação (`ReconciliacaoDoMonitor`), e por isso sem cache e sem
     * "último bom": dado velho aqui faria a reconciliação andar o cursor
     * sobre o que não viu.
     */
    public function notificacoes(int $depoisDe): RespostaDoMonitor
    {
        if (! $this->configurada()) {
            return RespostaDoMonitor::falha('O Matriz ainda não tem o endereço e o token de leitura do AlfaMonitor.');
        }

        try {
            $http = $this->pedir('notificacoes', ['depois_de' => $depoisDe, 'por_pagina' => self::POR_PAGINA], self::TIMEOUT_RELATORIO_S);
        } catch (Throwable $e) {
            // A causa vai junto (T-482): "não respondeu" sozinho não separa a
            // rede caída do certificado vencido nem do DNS, e o log de 22/10
            // ficou sem dizer qual foi.
            return RespostaDoMonitor::falha('O AlfaMonitor não respondeu ('.Str::limit($e->getMessage(), 300).').');
        }

        if ($http->status() === 404) {
            return RespostaDoMonitor::inexistente();
        }

        $dados = $http->json();

        if (! $http->successful() || ! is_array($dados)) {
            return RespostaDoMonitor::falha('O AlfaMonitor respondeu com erro ('.$http->status().').');
        }

        if (($dados['v'] ?? null) !== self::VERSAO_DO_CONTRATO) {
            return RespostaDoMonitor::falha('O AlfaMonitor mudou o formato da resposta; o Matriz precisa ser atualizado.');
        }

        return $this->resposta($dados);
    }

    /** Chamado pelo webhook: o próximo pedido de cada tela vai ao monitor. */
    public function invalidar(): void
    {
        Cache::forever(self::PREFIXO.'geracao', $this->geracao() + 1);
    }

    private function geracao(): int
    {
        return (int) Cache::get(self::PREFIXO.'geracao', 0);
    }

    /**
     * @param  array<string, scalar>  $parametros
     */
    private function pedir(string $rota, array $parametros, int $timeoutS, string $aceita = 'application/json'): Response
    {
        return Http::baseUrl(rtrim((string) config('services.alfamonitor.api_url'), '/').'/api/v1')
            ->withToken((string) config('services.alfamonitor.api_token'))
            ->accept($aceita)
            ->connectTimeout(3)
            ->timeout($timeoutS)
            ->get('/'.trim($rota, '/'), $parametros);
    }

    private function ultimoBom(string $chaveDoBom, string $motivo): RespostaDoMonitor
    {
        $bom = Cache::get($chaveDoBom);

        if (! is_array($bom)) {
            return RespostaDoMonitor::falha($motivo);
        }

        return $this->resposta($bom, velho: true, erro: $motivo);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function resposta(array $dados, bool $velho = false, ?string $erro = null): RespostaDoMonitor
    {
        $gerado = null;

        try {
            $gerado = isset($dados['gerado_em'])
                ? Carbon::parse($dados['gerado_em'])->setTimezone(config('app.timezone'))
                : null;
        } catch (Throwable) {
            // Hora ilegível não derruba a tela; ela só não diz de quando é.
        }

        return new RespostaDoMonitor($dados, $gerado, $velho, $erro);
    }
}
