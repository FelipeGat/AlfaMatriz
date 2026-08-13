<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
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

        $antes = $this->gradeAtual($perfil);

        $perfil->permissoes()->sync($grade->all());

        $this->registrarMudancaDeGrade($perfil, $antes, $this->gradeAtual($perfil->fresh()));

        return back()->with('status', "Permissões do perfil {$perfil->nome} salvas.");
    }

    /**
     * A grade como está agora, achatada em `recurso · ação => bool`.
     *
     * Achatada de propósito: a comparação que interessa é caixa a caixa, e uma
     * estrutura aninhada obrigaria a percorrer dois níveis só para descobrir
     * que uma delas virou.
     *
     * @return array<string, bool>
     */
    private function gradeAtual(Perfil $perfil): array
    {
        $grade = [];

        foreach ($perfil->permissoes()->get() as $permissao) {
            foreach (self::AÇÕES as $acao) {
                $grade[$permissao->recurso.' · '.$acao] = (bool) $permissao->pivot->{$acao};
            }
        }

        return $grade;
    }

    /**
     * O rastro da mudança de grade.
     *
     * Escrito à mão porque quem muda é `perfil_permissao`, tabela de ligação
     * sem modelo e sem evento do Eloquent — o trait de auditoria não a enxerga.
     * E é a mudança mais consequente do painel inteiro: mexer numa caixa aqui
     * altera de uma vez o que TODAS as contas daquele perfil alcançam, sem
     * tocar em nenhuma delas.
     *
     * Só as caixas que viraram entram na linha. A grade tem quinze recursos por
     * quatro ações; gravá-la inteira a cada salvamento sepultaria a única caixa
     * mexida no meio de cinquenta e nove que continuaram iguais.
     *
     * @param  array<string, bool>  $antes
     * @param  array<string, bool>  $depois
     */
    private function registrarMudancaDeGrade(Perfil $perfil, array $antes, array $depois): void
    {
        $mudou = [];

        // Percorre a união das duas, e não só o "depois": recurso criado por
        // uma migração nova aparece apenas de um lado, e ler só um deles
        // esconderia metade das diferenças possíveis.
        foreach (array_keys($antes + $depois) as $caixa) {
            $de = $antes[$caixa] ?? false;
            $para = $depois[$caixa] ?? false;

            if ($de !== $para) {
                $mudou[$caixa] = ['de' => $de ? 'sim' : 'não', 'para' => $para ? 'sim' : 'não'];
            }
        }

        if ($mudou === []) {
            return;
        }

        Auditoria::registrar(
            recurso: 'usuarios',
            acao: 'permissoes',
            alvo: $perfil,
            descricao: 'perfil '.$perfil->nome,
            alteracoes: $mudou,
        );
    }
}
