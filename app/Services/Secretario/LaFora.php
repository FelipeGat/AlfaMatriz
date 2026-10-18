<?php

namespace App\Services\Secretario;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Lá fora" do Meu Dia (docs/specs/secretario-central-do-dia, FR-001/FR-002):
 * dólar, clima de Vitória, futebol brasileiro e notícias de tecnologia, de fontes públicas e sem
 * chave, buscadas pelo cron a cada 30 min e lidas do cache — o Meu Dia nunca
 * espera uma fonte externa. Público: igual para todos, nada da conta sai.
 * Placar e próximo jogo ficaram de fora: a fonte gratuita testada devolveu
 * dados de outro time, e número inventado é pior que número ausente.
 */
class LaFora
{
    public const CHAVE = 'secretario:la-fora';
    public const VELHO_HORAS = 6;
    private const ESPERA_S = 8;

    /** Vitória-ES (resposta do Felipe, 17/10/2026). */
    private const VITORIA = ['latitude' => -20.32, 'longitude' => -40.34];

    public const FONTES = [
        'dolar' => 'https://economia.awesomeapi.com.br/json/last/USD-BRL',
        'clima' => 'https://api.open-meteo.com/v1/forecast',
        // Felipe, 18/10/2026: esporte é o futebol brasileiro (não só o Flamengo), e
        // notícia é só tecnologia — daqui (g1) e de fora (TechCrunch, em inglês).
        'futebol' => 'https://ge.globo.com/rss/ge/futebol/',
        'tec_brasil' => 'https://g1.globo.com/rss/g1/tecnologia/',
        'tec_mundo' => 'https://techcrunch.com/feed/',
    ];

    private const TEMPO = [0 => 'céu limpo', 1 => 'quase limpo', 2 => 'parcialmente nublado', 3 => 'nublado', 45 => 'neblina', 48 => 'neblina',
        51 => 'garoa', 53 => 'garoa', 55 => 'garoa forte', 61 => 'chuva fraca', 63 => 'chuva', 65 => 'chuva forte', 80 => 'pancadas',
        81 => 'pancadas', 82 => 'pancadas fortes', 95 => 'trovoada', 96 => 'trovoada com granizo', 99 => 'trovoada com granizo'];

    /** Busca as cinco fontes; a que falhar guarda o último dado bom (com a hora dele). */
    public function buscar(): array
    {
        $anterior = Cache::get(self::CHAVE, []);
        $novo = [];
        foreach (array_keys(self::FONTES) as $bloco) {
            try {
                $dado = $this->{$bloco}();
                $novo[$bloco] = ['dado' => $dado, 'em' => now()->toIso8601String(), 'fonte' => self::FONTES[$bloco]];
            } catch (\Throwable $e) {
                $novo[$bloco] = $anterior[$bloco] ?? null;
            }
        }
        Cache::put(self::CHAVE, $novo, now()->addDay());

        return $novo;
    }

    /** O que o Meu Dia mostra: cada bloco com a hora, ou null se não há dado ou ele passou de 6 h. */
    public function ler(): array
    {
        $tudo = Cache::get(self::CHAVE, []);
        $saida = [];
        foreach (array_keys(self::FONTES) as $bloco) {
            $b = $tudo[$bloco] ?? null;
            $em = $b ? CarbonImmutable::parse($b['em'])->setTimezone(config('app.timezone')) : null;
            $saida[$bloco] = $b && $em->greaterThan(now()->subHours(self::VELHO_HORAS)) ? $b['dado'] + ['em' => $em] : null;
        }

        return $saida;
    }

    private function dolar(): array
    {
        $j = Http::timeout(self::ESPERA_S)->acceptJson()->get(self::FONTES['dolar'])->throw()->json('USDBRL');

        return ['compra' => (float) $j['bid'], 'variacao' => (float) $j['pctChange'], 'maxima' => (float) $j['high'], 'minima' => (float) $j['low']];
    }

    private function clima(): array
    {
        $j = Http::timeout(self::ESPERA_S)->acceptJson()->get(self::FONTES['clima'], self::VITORIA + [
            'current' => 'temperature_2m,weather_code', 'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'timezone' => 'America/Sao_Paulo', 'forecast_days' => 1,
        ])->throw()->json();

        return [
            'agora' => (int) round($j['current']['temperature_2m']),
            'tempo' => self::TEMPO[(int) $j['current']['weather_code']] ?? 'variável',
            'maxima' => (int) round($j['daily']['temperature_2m_max'][0]),
            'minima' => (int) round($j['daily']['temperature_2m_min'][0]),
            'chuva' => (int) ($j['daily']['precipitation_probability_max'][0] ?? 0),
        ];
    }

    /** Quantas manchetes cada bloco guarda (o cartão mostra 3; "mais" abre o resto). */
    public const MANCHETES = 6;

    private function futebol(): array
    {
        return ['manchetes' => $this->rss(self::FONTES['futebol'], self::MANCHETES)];
    }

    private function tec_brasil(): array
    {
        return ['manchetes' => $this->rss(self::FONTES['tec_brasil'], self::MANCHETES)];
    }

    private function tec_mundo(): array
    {
        return ['manchetes' => $this->rss(self::FONTES['tec_mundo'], self::MANCHETES)];
    }

    /** @return list<array{titulo: string, link: ?string}> texto puro; link só https */
    public function rss(string $url, int $quantas): array
    {
        $xml = Http::timeout(self::ESPERA_S)->get($url)->throw()->body();
        $doc = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if (! $doc || ! isset($doc->channel->item)) {
            throw new \RuntimeException('rss');
        }
        $saida = [];
        foreach ($doc->channel->item as $item) {
            $titulo = Str::squish(html_entity_decode(strip_tags((string) $item->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $link = trim((string) $item->link);
            if ($titulo === '') {
                continue;
            }
            $saida[] = ['titulo' => Str::limit($titulo, 160), 'link' => str_starts_with($link, 'https://') ? $link : null];
            if (count($saida) >= $quantas) {
                break;
            }
        }
        if (! $saida) {
            throw new \RuntimeException('rss vazio');
        }

        return $saida;
    }
}
