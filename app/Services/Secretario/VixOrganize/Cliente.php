<?php

namespace App\Services\Secretario\VixOrganize;

use App\Models\SecretarioVixOrganize;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A API do VixOrganize (docs/specs/secretario-vixorganize): lê a fila e envia
 * as demandas da conta. Só https para IP público, com o IP já conferido fixado
 * na conexão e sem seguir redirect — a chave não sai do host (FR-003).
 */
class Cliente
{
    /** Quantos projetos abertos têm o detalhe (fases e passos) lido a cada vez. */
    public const DETALHES = 30;

    public function __construct(private SecretarioVixOrganize $conta) {}

    /** O endereço como guardar, ou a mensagem de por que não serve. @return array{0: ?string, 1: ?string} */
    public static function normalizarUrl(string $url): array
    {
        $url = rtrim(trim($url), '/');
        $partes = parse_url($url);
        $local = self::permiteLocal();
        if (! $partes || empty($partes['host']) || ! in_array($partes['scheme'] ?? '', $local ? ['https', 'http'] : ['https'], true)
            || isset($partes['user']) || isset($partes['query']) || isset($partes['fragment'])) {
            return [null, 'Use o endereço https do VixOrganize, como https://organize.vixcard.com.br.'];
        }
        if (! $local && ! self::ipPublico($partes['host'])) {
            return [null, 'Esse endereço não resolve para um servidor público.'];
        }

        return [$url, null];
    }

    /** A fila inteira e o detalhe dos abertos. @return array{projetos: list<array>, wip: int} */
    public function lerTudo(): array
    {
        $fila = $this->get('/api/projetos');
        $projetos = [];
        $abertos = 0;
        foreach ($fila as $p) {
            if (($p['status'] ?? '') !== 'concluido' && $abertos++ < self::DETALHES) {
                $p = $this->get('/api/projetos/'.rawurlencode((string) $p['slug']));
            }
            $projetos[] = $p;
        }

        return ['projetos' => $projetos, 'em_andamento' => collect($projetos)->where('status', 'em_andamento')->count()];
    }

    public function criarProjeto(array $dados): array
    {
        return $this->post('/api/projetos', $dados);
    }

    public function status(string $slug, string $status): array
    {
        return $this->post($this->caminho($slug, 'status'), ['status' => $status]);
    }

    public function novoPasso(string $slug, string $titulo, ?string $notas, ?int $faseId): array
    {
        return $this->post($this->caminho($slug, 'passos'), array_filter(['title' => $titulo, 'notes' => $notas, 'phase_id' => $faseId], fn ($v) => $v !== null && $v !== ''))['project'];
    }

    public function marcar(string $slug, int $passo, bool $feito): array
    {
        return $this->post($this->caminho($slug, "passos/{$passo}/check"), ['done' => $feito]);
    }

    public function registrar(string $slug, int $passo, string $texto): void
    {
        $this->post($this->caminho($slug, "passos/{$passo}/registros"), ['body' => $texto]);
    }

    private function caminho(string $slug, string $resto): string
    {
        return '/api/projetos/'.rawurlencode($slug).'/'.$resto;
    }

    private function get(string $caminho): array
    {
        return $this->resposta(fn () => $this->pedido()->get($caminho));
    }

    /** POST uma vez só: sem retry, para não lançar a mesma demanda duas vezes (FR-005). */
    private function post(string $caminho, array $corpo): array
    {
        return $this->resposta(fn () => $this->pedido()->post($caminho, $corpo));
    }

    private function resposta(callable $fazer): array
    {
        try {
            /** @var Response $r */
            $r = $fazer();
        } catch (ConnectionException) {
            throw new ErroDoVixOrganize('O VixOrganize não respondeu.');
        }
        if ($r->successful() && is_array($r->json('data'))) {
            return $r->json('data');
        }
        throw new ErroDoVixOrganize(match (true) {
            $r->status() === 401 => 'O VixOrganize recusou a chave. Confira a chave de escrita em Configurações.',
            $r->status() === 404 => 'O VixOrganize não achou esse projeto ou passo — ele pode ter sido apagado lá.',
            $r->status() === 422 => 'O VixOrganize recusou: '.Str::limit(strip_tags((string) ($r->json('message') ?? 'dados inválidos')), 140),
            $r->status() === 429 => 'Muitos pedidos ao VixOrganize. Tente em um minuto.',
            $r->redirect() => 'O endereço do VixOrganize redireciona para outro lugar. Confira em Configurações.',
            default => 'O VixOrganize respondeu com erro ('.$r->status().').',
        });
    }

    private function pedido(): PendingRequest
    {
        $partes = parse_url($this->conta->url);
        $opcoes = ['allow_redirects' => false];
        if (! self::permiteLocal()) {
            // A conexão vai ao IP conferido agora, não a uma segunda resolução.
            $ip = self::ipPublico($partes['host'] ?? '');
            if (! $ip) {
                throw new ErroDoVixOrganize('O endereço do VixOrganize não resolve para um servidor público.');
            }
            $porta = $partes['port'] ?? 443;
            $opcoes['curl'] = [CURLOPT_RESOLVE => ["{$partes['host']}:{$porta}:{$ip}"]];
        }

        return Http::baseUrl($this->conta->url)->acceptJson()->asJson()->timeout(12)->connectTimeout(5)->withOptions($opcoes)
            ->withHeaders(array_filter(['X-Api-Key' => $this->conta->chave, 'X-Vix-Autor' => $this->conta->autor_email, 'X-Vix-Origem' => 'AlfaMatriz']));
    }

    /**
     * O http e o IP privado do teste local ponta a ponta. Nunca em produção, nem
     * com a variável ligada: um .env copiado de outro ambiente abriria o SSRF
     * sem ninguém ver (T-507).
     */
    private static function permiteLocal(): bool
    {
        return (bool) config('services.vixorganize.permite_local') && ! app()->isProduction();
    }

    private static function ipPublico(string $host): ?string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null; // um IP interno no meio já basta para recusar
            }
        }

        return $ips[0] ?? null;
    }
}
