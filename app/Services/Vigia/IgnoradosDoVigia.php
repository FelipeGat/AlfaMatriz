<?php

namespace App\Services\Vigia;

use App\Models\Sistema;
use App\Models\VigiaErro;
use App\Models\VigiaIgnorado;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A lista do que o vigia de logs não acompanha (#219), e quem ela cobre.
 *
 * Nasceu dentro do `alfa:vigia-ignorar`; saiu dele quando a aba Erros da tela
 * de Manutenção (#224) ganhou o mesmo botão. Duas portas para a mesma lista
 * precisam da mesma regra — a regex testada na entrada, o padrão de um erro
 * convertido sem virar regex por acidente, e a marca `ignorado` dos erros
 * acertada na hora.
 *
 * Por que acertar a marca na hora, se o recebimento já a recalcula a cada
 * lote: a tela mostra a marca. Sem isso, quem clica "Ignorar" veria o erro
 * continuar na lista dos vigiados até o próximo lote chegar — até uma hora
 * depois —, e clicaria de novo.
 */
class IgnoradosDoVigia
{
    /**
     * Acrescenta um padrão à lista. Repetido não duplica: devolve o que já
     * estava lá.
     *
     * @throws InvalidArgumentException regex inválida, ou padrão vazio
     */
    public function ignorar(string $padrao, ?Sistema $sistema = null): VigiaIgnorado
    {
        $padrao = trim($padrao);

        if ($padrao === '') {
            throw new InvalidArgumentException('Diga o que ignorar.');
        }

        $item = new VigiaIgnorado(['padrao' => $padrao, 'sistema_id' => $sistema?->id]);

        // A regex é testada na entrada: inválida, ela nunca casaria, e o erro
        // que alguém quis calar continuaria abrindo tarefa sem ninguém saber
        // por quê.
        if ($item->ehRegex() && @preg_match($padrao.'iu', '') === false) {
            throw new InvalidArgumentException('Expressão regular inválida: '.$padrao);
        }

        $item = VigiaIgnorado::firstOrCreate(['padrao' => $padrao, 'sistema_id' => $sistema?->id]);
        $this->recalcular();

        return $item;
    }

    /**
     * Ignora UM erro, pelo padrão dele, só no sistema dele — o botão da tela.
     *
     * O padrão normalizado (`Pedido {n} não encontrado`) é o que casa com as
     * próximas ocorrências da mesma assinatura. Se ele por acaso começar e
     * terminar em barra (um caminho, `/var/log/`), seria lido como regex e
     * casaria com outra coisa: aí ele entra escapado, como regex literal.
     */
    public function ignorarErro(VigiaErro $erro): VigiaIgnorado
    {
        $padrao = $erro->padrao;

        if ((new VigiaIgnorado(['padrao' => $padrao]))->ehRegex()) {
            $padrao = '/'.preg_quote($padrao, '/').'/';
        }

        return $this->ignorar($padrao, $erro->sistema);
    }

    /** Tira um padrão da lista pelo texto, como o `--remover` do comando. */
    public function remover(string $padrao, ?Sistema $sistema = null): int
    {
        $quantos = VigiaIgnorado::where('padrao', trim($padrao))->where('sistema_id', $sistema?->id)->get()
            ->each->delete()
            ->count();

        if ($quantos > 0) {
            $this->recalcular();
        }

        return $quantos;
    }

    public function deixarDeIgnorar(VigiaIgnorado $item): void
    {
        $item->delete();
        $this->recalcular();
    }

    /**
     * Acerta a marca `ignorado` de todos os erros contra a lista atual.
     *
     * Todos, e não só os do sistema do padrão: um padrão global mexe em todo
     * sistema. A tabela é pequena — uma linha por assinatura, não por
     * ocorrência —, e só o que mudou é gravado.
     */
    public function recalcular(): void
    {
        $regras = VigiaIgnorado::all();

        VigiaErro::query()->select(['id', 'sistema_id', 'padrao', 'excecao', 'mensagem', 'ignorado'])
            ->lazyById()
            ->each(function (VigiaErro $erro) use ($regras) {
                $deve = $this->regraDe($erro, $regras) !== null;

                if ($erro->ignorado !== $deve) {
                    $erro->forceFill(['ignorado' => $deve])->saveQuietly();
                }
            });
    }

    /**
     * A regra que cobre o erro, se alguma cobre. A do próprio sistema vem
     * antes da global: é ela que a tela oferece desfazer na linha do erro.
     *
     * @param  Collection<int, VigiaIgnorado>  $regras
     */
    public function regraDe(VigiaErro $erro, Collection $regras): ?VigiaIgnorado
    {
        return $regras
            ->filter(fn (VigiaIgnorado $r) => $r->sistema_id === null || (int) $r->sistema_id === (int) $erro->sistema_id)
            ->sortBy(fn (VigiaIgnorado $r) => $r->sistema_id === null ? 1 : 0)
            ->first(fn (VigiaIgnorado $r) => self::cobre($r, $erro));
    }

    /**
     * A regra casa com o texto cru do erro OU com o padrão normalizado: quem
     * ignora pela linha de comando costuma copiar a mensagem como apareceu; o
     * botão da tela ignora pelo padrão.
     */
    public static function cobre(VigiaIgnorado $regra, VigiaErro $erro): bool
    {
        $texto = trim(($erro->excecao ? $erro->excecao.': ' : '').$erro->mensagem);

        return $regra->casaCom($texto) || $regra->casaCom($erro->padrao);
    }
}
