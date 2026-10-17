<?php

namespace App\Services\Monitor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    private const PREFIXO = 'alfamonitor-api:';

    public function configurada(): bool
    {
        return filled(config('services.alfamonitor.url')) && filled(config('services.alfamonitor.api_token'));
    }

    /**
     * @param  array<string, scalar|null>  $parametros
     */
    public function buscar(string $rota, array $parametros = [], int $cacheS = self::CACHE_VIVO_S, int $timeoutS = self::TIMEOUT_S): RespostaDoMonitor
    {
        if (! $this->configurada()) {
            return RespostaDoMonitor::falha('O Matriz ainda não tem o endereço e o token de leitura do AlfaMonitor (ALFAMONITOR_URL e ALFAMONITOR_API_TOKEN).');
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

        try {
            $http = $this->pedir($rota, $parametros, $timeoutS);
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
        return Http::baseUrl(rtrim((string) config('services.alfamonitor.url'), '/').'/api/v1')
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
