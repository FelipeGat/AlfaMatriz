<?php

namespace App\Services\Secretario;

use App\Models\SecretarioEmail;
use App\Models\SecretarioEmailConta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Quais e-mails importantes chegaram hoje?" e "tenho algum cliente
 * aguardando resposta?" — respondidas com os metadados guardados, sem IA
 * (spec FR-008). "Resume os e-mails da VixCard" vai à IA da pessoa (FR-009).
 * Só entra para quem tem caixa cadastrada.
 */
class ConsultaDeEmails
{
    private const EMAIL = '/\b(e-?mails?|emails?|mensagens? de e-?mail|caixa de entrada)\b/u';

    public function responder(User $user, string $texto): ?string
    {
        if (! SecretarioEmailConta::de($user)->exists()) {
            return null;
        }
        $t = $this->normalizar($texto);
        if (preg_match('/\b(aguardando|esperando)( a)? (minha )?resposta|\b(preciso|tenho que) responder\b|\bsem resposta\b/u', $t)) {
            return $this->aguardando($user);
        }
        if (preg_match('/^(resum[eoa]|resumir|me resume|faz um resumo)\b.*'.substr(self::EMAIL, 1, -2).'/u', $t)) {
            $rotulo = preg_match('/\bd[aoe]s? ([a-z0-9 .-]{2,40})$/u', $t, $m) ? trim($m[1]) : null;

            return app(PedidoDeEmailIa::class)->resumo($user, $rotulo);
        }
        if (preg_match(self::EMAIL, $t) && preg_match('/\b(importantes?|chegaram|chegou|novos?|recebi|hoje|ontem)\b/u', $t)) {
            return $this->importantes($user, str_contains($t, 'ontem') ? CarbonImmutable::now()->subDay() : CarbonImmutable::now());
        }

        return null;
    }

    public function importantes(User $user, CarbonImmutable $dia): string
    {
        $todos = SecretarioEmail::de($user)->with('conta')->where('enviado', false)
            ->whereBetween('recebido_em', [$dia->startOfDay(), $dia->endOfDay()])->orderBy('recebido_em')->get();
        $importantes = $todos->filter(fn ($e) => $e->urgencia === 'alta' || ($e->pede_retorno && ! $e->respondido));
        $quando = $dia->isToday() ? 'hoje' : Datas::porExtenso($dia, true);
        $linhas = ["📧 E-mails importantes {$quando}: ".$importantes->count().' de '.$todos->count().' recebido(s).'];
        foreach ($importantes->take(15) as $e) {
            $linhas[] = '• '.$this->linha($e);
        }

        return implode("\n", array_merge($linhas, [$this->rodape($user)]));
    }

    public function aguardando(User $user): string
    {
        $lista = SecretarioEmail::de($user)->with('conta')->aguardando()->where('recebido_em', '>=', now()->subDays(7))->orderBy('recebido_em')->get();
        $linhas = ['📨 Aguardando sua resposta (últimos 7 dias): '.$lista->count().'.'];
        foreach ($lista->take(15) as $e) {
            $linhas[] = '• '.$this->linha($e, true);
        }
        if ($lista->isEmpty()) {
            $linhas[] = 'Nenhuma mensagem pedindo retorno sem resposta nos enviados.';
        }

        return implode("\n", array_merge($linhas, [$this->rodape($user)]));
    }

    /** @return Collection<int, SecretarioEmail> */
    public function aguardandoLista(User $user): Collection
    {
        return SecretarioEmail::de($user)->with('conta')->aguardando()->where('recebido_em', '>=', now()->subDays(7))->orderBy('recebido_em')->get();
    }

    public function linha(SecretarioEmail $e, bool $comDia = false): string
    {
        $quando = $comDia ? $e->recebido_em->format('d/m H:i') : $e->recebido_em->format('H:i');
        $marcas = array_filter([$e->tipo !== 'outro' ? $e->tipo : null, $e->urgencia === 'alta' ? 'urgente' : null,
            $e->pede_retorno && ! $e->respondido ? 'aguardando resposta' : null]);

        return "{$quando} ".($e->de_nome ?: $e->de_email).' — '.Str::limit($e->assunto, 90)
            .($marcas ? ' ('.implode(', ', $marcas).')' : '').' · '.$e->conta?->rotulo;
    }

    /** Diz quando cada caixa foi lida, e a que está com erro (FR-007). */
    public function rodape(User $user): string
    {
        $partes = SecretarioEmailConta::de($user)->orderBy('rotulo')->get()->map(fn ($c) => $c->situacao === 'erro'
            ? "{$c->rotulo}: não lida desde ".($c->lida_em?->format('d/m H:i') ?? 'o cadastro').' ⚠'
            : "{$c->rotulo}: lida às ".($c->lida_em?->format('H:i') ?? '—'));

        return 'Caixas: '.$partes->implode('; ').'.';
    }

    private function normalizar(string $t): string
    {
        $t = mb_strtolower(trim($t), 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return trim(preg_replace('/^(matriz|secretario)[,:!]?\s+/u', '', $t), " ?!.");
    }
}
