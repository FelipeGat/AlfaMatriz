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
    public function marcarLidas(Request $request)
    {
        Notificacao::where('destinatario_id', $request->user()->id)
            ->whereNull('lida_em')
            ->update(['lida_em' => now()]);

        return back();
    }
}
