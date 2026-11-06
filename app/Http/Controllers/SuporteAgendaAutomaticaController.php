<?php

namespace App\Http\Controllers;

use App\Services\Suporte\AcessoInterno;
use App\Services\Suporte\AgendaAutomatica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Suporte › Agenda automática (docs/specs/pedro-agenda-automatica, US4): quem recebe a agenda pelo WhatsApp, os
 * horários, o modo, a prévia e o que saiu. Quem tem `suporte` vê; mudar é só de quem administra o Pedro (Decision 16),
 * a mesma regra da lista da casa, porque mexe em quem recebe mensagem e em ligar o envio de verdade. A regra e os
 * dados moram no Gestor: aqui não se guarda nada, e toda mudança vai com o e-mail de quem mudou (FR-005).
 */
class SuporteAgendaAutomaticaController extends Controller
{
    public function index(Request $request, AgendaAutomatica $agenda): View
    {
        $filtros = $this->filtros($request);
        $pagina = max(1, min(10000, (int) $request->query('pagina', 1)));
        $administra = AcessoInterno::administra($request->user());
        $erro = null;
        $estado = $funcionarios = $avisos = null;
        // Uma chamada por vez, parando na primeira falha: com o Gestor fora, as três dariam o mesmo recado.
        try {
            $estado = $agenda->estado();
            $funcionarios = $administra ? $agenda->funcionarios() : null;
            $avisos = $agenda->avisos($filtros, $pagina);
        } catch (ValidationException $e) {
            $erro = $e->validator->errors()->first();
        }

        return view('suporte.agenda-automatica', compact('estado', 'funcionarios', 'avisos', 'filtros', 'pagina', 'administra', 'erro'));
    }

    public function incluir(Request $request, AgendaAutomatica $agenda): RedirectResponse
    {
        abort_unless(AcessoInterno::administra($request->user()), 403);
        $d = $request->validate([
            'funcionario_id' => ['required', 'integer', 'min:1'],
            'papel' => ['required', Rule::in(array_keys(AgendaAutomatica::PAPEIS))],
        ]);
        $dest = $agenda->incluir((int) $d['funcionario_id'], $d['papel'], $request->boolean('consentimento'), $this->autor($request));

        return back()->with('status', trim(($dest['nome'] ?? '').' está na lista de '.mb_strtolower(AgendaAutomatica::PAPEIS[$d['papel']]).'s.'));
    }

    public function mudar(Request $request, AgendaAutomatica $agenda, int $destinatario): RedirectResponse
    {
        abort_unless(AcessoInterno::administra($request->user()), 403);
        $acao = $request->validate(['acao' => ['required', Rule::in(['ativar', 'desativar', 'consentimento'])]])['acao'];
        $dest = $agenda->mudar($destinatario, match ($acao) {
            'ativar' => ['ativo' => true],
            'desativar' => ['ativo' => false],
            'consentimento' => ['consentimento' => true],
        }, $this->autor($request));

        return back()->with('status', ($dest['nome'] ?: 'Destinatário').': '.match ($acao) {
            'ativar' => 'ativado.',
            'desativar' => 'desativado; o histórico continua.',
            'consentimento' => 'consentimento registrado agora, em seu nome.',
        });
    }

    /**
     * Ligar é o único passo que manda mensagem de verdade (FR-025): pede a confirmação marcada, para não sair de
     * um clique distraído no seletor. Os horários seguem a regra do Gestor, que é quem recusa a ordem errada.
     */
    public function configurar(Request $request, AgendaAutomatica $agenda): RedirectResponse
    {
        abort_unless(AcessoInterno::administra($request->user()), 403);
        $hora = ['required', 'date_format:H:i'];
        $d = $request->validate([
            'modo' => ['required', Rule::in(array_keys(AgendaAutomatica::MODOS))],
            'modo_atual' => ['nullable', 'string', 'max:20'],
            'manha' => $hora, 'programacao' => $hora, 'relatorio' => $hora, 'silencio_inicio' => $hora,
            'confirmar_ligada' => [Rule::requiredIf(fn () => $request->input('modo') === 'ligada' && $request->input('modo_atual') !== 'ligada')],
        ], ['confirmar_ligada.required' => 'Para ligar, marque que as mensagens passam a sair de verdade.']);
        $r = $agenda->configurar(collect($d)->only(['modo', 'manha', 'programacao', 'relatorio', 'silencio_inicio'])->all(), $this->autor($request));

        return back()->with('status', 'Configuração salva no Gestor: modo '.mb_strtolower(AgendaAutomatica::MODOS[$r['configuracao']['modo']] ?? (string) $r['configuracao']['modo'])
            .($r['envio_real'] ? ', com envio de verdade.' : ', sem envio de verdade.'));
    }

    /** Grava como ensaio no Gestor e abre a lista já filtrada nesses avisos, com o texto exato de cada um. */
    public function previa(Request $request, AgendaAutomatica $agenda): RedirectResponse
    {
        abort_unless(AcessoInterno::administra($request->user()), 403);
        $d = $request->validate([
            'tipo' => ['required', Rule::in(['programacao', 'relatorio'])],
            'dia' => ['required', Rule::in(['hoje', 'amanha'])],
        ]);
        $alvo = $d['dia'] === 'hoje' ? now() : now()->addDay();
        $dia = $alvo->toDateString();
        $avisos = $agenda->previa($d['tipo'], $dia, $this->autor($request));

        return redirect()->route('suporte.agenda-automatica', ['modo' => 'ensaio', 'tipo' => $d['tipo'], 'dia' => $dia])
            ->with('status', count($avisos) === 0
                ? 'Prévia feita: ninguém da lista tem aviso para '.$alvo->format('d/m').'.'
                : 'Prévia feita: '.count($avisos).(count($avisos) === 1 ? ' aviso' : ' avisos').' de ensaio para '.$alvo->format('d/m').'. Nada foi enviado.');
    }

    /** Os filtros da lista de envios: só valor que o Gestor conhece segue adiante; o resto é ignorado. */
    private function filtros(Request $request): array
    {
        $dia = (string) $request->query('dia', '');

        return [
            'dia' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) ? $dia : null,
            'tipo' => array_key_exists((string) $request->query('tipo'), AgendaAutomatica::TIPOS) ? (string) $request->query('tipo') : null,
            'situacao' => array_key_exists((string) $request->query('situacao'), AgendaAutomatica::SITUACOES) ? (string) $request->query('situacao') : null,
            'modo' => in_array($request->query('modo'), ['ensaio', 'real'], true) ? (string) $request->query('modo') : null,
        ];
    }

    /** Quem mudou, como o Gestor grava no activity_log dele (até 120 caracteres, pelo contrato). */
    private function autor(Request $request): string
    {
        return mb_substr(mb_strtolower((string) $request->user()->email), 0, 120);
    }
}
