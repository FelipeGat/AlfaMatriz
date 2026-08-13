<?php

namespace App\Http\Controllers;

use App\Models\Perfil;
use App\Models\Permissao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A grade de permissões de um perfil.
 *
 * O perfil `admin` é IMUTÁVEL, e é essa decisão que torna a tela segura: sem
 * ela, o único administrador do sistema poderia tirar o recurso `usuarios` do
 * próprio perfil e ninguém — inclusive ele — reabriria esta tela. Não se perde
 * nada em troca: o administrador tem os quatro verbos em todos os recursos por
 * definição, então não há o que ajustar ali.
 *
 * A trava mora no servidor, e não no `disabled` da view: caixa desabilitada é
 * uma informação para quem está olhando, não uma regra para quem envia o
 * formulário à mão.
 */
class PerfilController extends Controller
{
    private const AÇÕES = ['ler', 'incluir', 'imprimir', 'excluir'];

    public function __construct()
    {
        $this->bloquearVisaoDaMatriz();
    }

    public function update(Request $request, Perfil $perfil): RedirectResponse
    {
        abort_if(
            $perfil->slug === 'admin',
            403,
            'O perfil Administrador tem acesso total por definição e não é editável.'
        );

        $request->validate([
            'grade' => ['array'],
            'grade.*' => ['array'],
        ]);

        $enviado = (array) $request->input('grade', []);

        // Percorre TODOS os recursos, e não só os que vieram no envio: caixa
        // desmarcada não viaja em formulário HTML, então o que o navegador
        // manda é a lista do que ficou ligado. Sincronizar apenas o recebido
        // faria desmarcar não desmarcar nada.
        $grade = Permissao::all()->mapWithKeys(function (Permissao $permissao) use ($enviado) {
            $marcadas = (array) ($enviado[$permissao->recurso] ?? []);

            return [$permissao->id => array_map(
                fn (string $acao) => (bool) ($marcadas[$acao] ?? false),
                array_combine(self::AÇÕES, self::AÇÕES)
            )];
        });

        $perfil->permissoes()->sync($grade->all());

        return back()->with('status', "Permissões do perfil {$perfil->nome} salvas.");
    }
}
