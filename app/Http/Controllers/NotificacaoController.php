<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use Illuminate\Http\Request;

/**
 * O sino. Uma ação só: dar por lido.
 *
 * Não há rota de listagem porque o painel não é uma tela — ele é servido junto
 * de todas as outras, pelo `ComporSino`, e abre sem ida ao servidor. Uma rota
 * de listar existiria só para ser chamada por um endereço que ninguém digita.
 */
class NotificacaoController extends Controller
{
    /**
     * Marca lidas.
     *
     * Sem id: "Marcar lidas" é o botão do cabeçalho, e ele fala do painel
     * inteiro. Marcar uma a uma seria a ação de outro desenho — aqui o item
     * clicado leva para a tela onde a coisa se resolve, e é resolvê-la que
     * importa, não catalogar o que já se leu.
     */
    /**
     * O contador do sino e o id da notificação mais recente — em JSON, leve.
     *
     * É o que o poll do shell busca a cada ~45s para o sino se atualizar sozinho
     * sem recarregar a página. Duas coisas, e as duas importam: `nao_lidas` é o
     * número da bolinha; `ultimo_id` é como o navegador percebe que CHEGOU algo
     * novo — quando ele passa do que a página conhecia, o sino pulsa e o aviso
     * flutuante aparece. Sem o id, o poll saberia "há não lidas", mas não
     * "acabou de chegar", e um contador que já estava em 3 não alertaria de um
     * quarto aviso.
     *
     * Duas contagens rasas por chamada, sem carregar linha nenhuma: o poll é
     * frequente, e trazer as notificações aqui seria pagar o painel inteiro a
     * cada 45 segundos por uma bolinha.
     */
    public function resumo(Request $request)
    {
        $id = $request->user()->id;

        return response()->json([
            'nao_lidas' => Notificacao::naoLidasDe($id)->count(),
            'ultimo_id' => (int) Notificacao::where('destinatario_id', $id)->max('id'),
        ]);
    }

    /**
     * A lista do painel, renderizada — o que o poll injeta quando algo chega.
     *
     * Devolve o MESMO partial que o painel usa na primeira carga, então a linha
     * é idêntica venha de onde vier. É HTML e não JSON de propósito: a marcação
     * (a barra da não lida, o tom por nível, o tempo relativo) já existe no
     * Blade, e reconstruí-la no navegador seria uma segunda cópia para
     * divergir.
     */
    public function lista(Request $request)
    {
        return view('layouts._notificacoes-lista', [
            'notificacoes' => Notificacao::where('destinatario_id', $request->user()->id)
                ->latest('id')->limit(12)->get(),
        ]);
    }

    public function marcarLidas(Request $request)
    {
        Notificacao::where('destinatario_id', $request->user()->id)
            ->whereNull('lida_em')
            ->update(['lida_em' => now()]);

        return back();
    }
}
