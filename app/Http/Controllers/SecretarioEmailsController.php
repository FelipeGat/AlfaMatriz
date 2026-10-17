<?php

namespace App\Http\Controllers;

use App\Models\SecretarioEmail;
use App\Models\SecretarioEmailConta;
use App\Models\SecretarioItem;
use App\Services\CentralIa\Eventos;
use App\Services\Secretario\Despachante;
use App\Services\Secretario\Email\LeitorDeEmails;
use App\Services\Secretario\PedidoDeEmailIa;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Caixas IMAP e Mensagens do Secretário (docs/specs/secretario-emails-gestor).
 * A senha entra aqui e não sai mais: nenhuma resposta a devolve (FR-002).
 * Não existe envio de e-mail neste sistema (FR-003, FR-009).
 */
class SecretarioEmailsController extends Controller
{
    private const MENSAGENS_DE_ERRO = [
        'conexao' => 'Não consegui conectar ao servidor (endereço, porta ou rede).',
        'tls' => 'O servidor não aceitou a conexão cifrada.', 'saudacao' => 'O servidor não respondeu como IMAP.',
        'login' => 'Usuário ou senha recusados. Em Gmail/Outlook, use uma senha de app.',
        'pasta' => 'A pasta de entrada não existe nessa caixa.', 'tempo' => 'O servidor demorou demais para responder.',
        'credencial_invalida' => 'Usuário ou senha com caractere inválido.', 'sem_cifra' => 'Só conexão cifrada (SSL ou TLS).',
    ];

    public function index(Request $request): View
    {
        $f = $request->validate([
            'ver' => ['nullable', Rule::in(['aguardando', 'importantes', 'todas'])], 'conta' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['nao_lidos', 'aguardando', 'respondidas', 'favoritos'])],
            'dias' => ['nullable', Rule::in(['1', '3', '7', '30'])], 'q' => ['nullable', 'string', 'max:120'],
        ]);
        $filtro = $f['ver'] ?? 'importantes';
        $dias = (int) ($f['dias'] ?? 7);
        $busca = trim((string) ($f['q'] ?? ''));
        $emails = SecretarioEmail::de($request->user())->with('conta')->where('enviado', false)->where('recebido_em', '>=', now()->subDays($dias)->startOfDay())
            ->when($filtro === 'aguardando', fn ($q) => $q->aguardando())
            // Importantes: urgente, com estrela, aguardando resposta ou ainda não lida.
            ->when($filtro === 'importantes', fn ($q) => $q->where(fn ($w) => $w->where('urgencia', 'alta')->orWhere('favorito', true)->orWhere('lido', false)
                ->orWhere(fn ($x) => $x->where('pede_retorno', true)->where('respondido', false))))
            ->when($f['conta'] ?? null, fn ($q, $c) => $q->where('conta_id', $c))
            ->when(($f['status'] ?? null) === 'nao_lidos', fn ($q) => $q->where('lido', false))
            ->when(($f['status'] ?? null) === 'aguardando', fn ($q) => $q->aguardando())
            ->when(($f['status'] ?? null) === 'respondidas', fn ($q) => $q->where('respondido', true))
            ->when(($f['status'] ?? null) === 'favoritos', fn ($q) => $q->where('favorito', true))
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $busca).'%';
                $q->where(fn ($w) => $w->where('assunto', 'like', $termo)->orWhere('de_nome', 'like', $termo)->orWhere('de_email', 'like', $termo));
            })
            ->orderByDesc('recebido_em')->paginate(30)->withQueryString();

        return view('secretario.emails', ['emails' => $emails, 'filtro' => $filtro, 'filtros' => $f + ['dias' => (string) $dias],
            'contas' => SecretarioEmailConta::de($request->user())->orderBy('rotulo')->get()]);
    }

    /**
     * O texto da mensagem, lido AGORA no servidor de e-mail (BODY.PEEK: não marca
     * como lida) e devolvido sem guardar — o Matriz só guarda metadados (FR-004).
     */
    public function texto(Request $request, int $email, LeitorDeEmails $leitor): JsonResponse
    {
        $e = SecretarioEmail::de($request->user())->with('conta')->findOrFail($email);
        $texto = $e->conta ? ($leitor->textosAgora($e->conta, [$e], 20000)[$e->id] ?? null) : null;

        return response()->json(['texto' => $texto], $texto === null ? 502 : 200)->header('Cache-Control', 'no-store, private');
    }

    /** Começo do texto das mensagens da página (até 30), lido agora e não guardado. */
    public function previas(Request $request, LeitorDeEmails $leitor): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:30'], 'ids.*' => ['integer']])['ids'];
        $saida = [];
        foreach (SecretarioEmail::de($request->user())->with('conta')->whereIn('id', $ids)->get()->groupBy('conta_id') as $doConta) {
            foreach ($leitor->textosAgora($doConta->first()->conta, $doConta, 600) as $id => $t) {
                $saida[$id] = Str::limit(Str::squish($t), 140);
            }
        }

        return response()->json(['previas' => $saida])->header('Cache-Control', 'no-store, private');
    }

    public function favoritar(Request $request, int $email): JsonResponse
    {
        $e = SecretarioEmail::de($request->user())->findOrFail($email);
        $e->update(['favorito' => ! $e->favorito]);

        return response()->json(['favorito' => $e->favorito]);
    }

    private function regras(): array
    {
        return [
            'rotulo' => ['required', 'string', 'max:60'],
            'host' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9.-]+$/'],
            // Só as portas de IMAP: o servidor não serve de sonda para a rede (revisão de QA).
            'porta' => ['required', 'integer', 'in:993,143'],
            'seguranca' => ['required', Rule::in(['ssl', 'tls'])],
            'usuario' => ['required', 'string', 'max:190'],
            'senha' => ['required', 'string', 'max:500'],
            // Sem quebra de linha: ela viraria um segundo comando IMAP (STORE, EXPUNGE).
            'pasta_entrada' => ['required', 'string', 'max:120', 'regex:/^[^\r\n\x00]+$/'],
            'pasta_enviados' => ['nullable', 'string', 'max:120', 'regex:/^[^\r\n\x00]+$/'],
        ];
    }

    public function testar(Request $request, LeitorDeEmails $leitor): JsonResponse
    {
        $d = $request->validate($this->regras());
        $r = $leitor->testar($d['host'], (int) $d['porta'], $d['seguranca'], $d['usuario'], $d['senha'], $d['pasta_entrada']);

        return response()->json($r['ok'] ? ['ok' => true, 'mensagens' => $r['mensagens'], 'pastas' => $r['pastas']]
            : ['ok' => false, 'erro' => self::MENSAGENS_DE_ERRO[$r['erro']] ?? 'Não consegui ler a caixa.'], $r['ok'] ? 200 : 422);
    }

    public function cadastrar(Request $request, LeitorDeEmails $leitor): JsonResponse
    {
        $d = $request->validate($this->regras());
        // Só salva caixa que conecta: senha errada não fica guardada.
        $t = $leitor->testar($d['host'], (int) $d['porta'], $d['seguranca'], $d['usuario'], $d['senha'], $d['pasta_entrada']);
        if (! $t['ok']) {
            return response()->json(['ok' => false, 'erro' => self::MENSAGENS_DE_ERRO[$t['erro']] ?? 'Não consegui ler a caixa.'], 422);
        }
        $c = SecretarioEmailConta::create(['user_id' => $request->user()->id] + $d);
        Eventos::registrar($request->user(), 'secretario_email_caixa', "Caixa de e-mail #{$c->id} ({$c->rotulo}) ligada.", ['origem' => 'web']);
        $leitor->sincronizar($c);

        return response()->json(['ok' => true, 'id' => $c->id], 201);
    }

    public function remover(Request $request, int $conta): RedirectResponse
    {
        $c = SecretarioEmailConta::de($request->user())->findOrFail($conta);
        $c->delete(); // mensagens saem junto (cascade); a senha some com a linha
        Eventos::registrar($request->user(), 'secretario_email_caixa', "Caixa de e-mail #{$conta} removida.", ['origem' => 'web']);

        return redirect()->route('secretario.configuracoes')->with('status', 'Caixa removida; a senha e as mensagens guardadas dela foram apagadas.');
    }

    public function ler(Request $request, int $conta, LeitorDeEmails $leitor): JsonResponse
    {
        $r = $leitor->sincronizar(SecretarioEmailConta::de($request->user())->findOrFail($conta));

        return response()->json($r['ok'] ? ['ok' => true, 'novas' => $r['novas']] : ['ok' => false, 'erro' => self::MENSAGENS_DE_ERRO[$r['erro']] ?? 'Não consegui ler a caixa.'], $r['ok'] ? 200 : 502);
    }

    public function rascunho(Request $request, int $email, PedidoDeEmailIa $ia): JsonResponse
    {
        $e = SecretarioEmail::de($request->user())->findOrFail($email);

        return response()->json(['mensagem' => $ia->rascunho($request->user(), $e)]);
    }

    public function lembrar(Request $request, int $email, Despachante $despachante): JsonResponse
    {
        $e = SecretarioEmail::de($request->user())->with('conta')->findOrFail($email);
        $quando = CarbonImmutable::tomorrow()->setTime(9, 0);
        $ref = 'email:'.$e->id;
        $item = SecretarioItem::de($request->user())->where('origem', 'email')->where('origem_ref', $ref)->where('status', SecretarioItem::ABERTO)->first()
            ?? SecretarioItem::create([
                'user_id' => $request->user()->id, 'tipo' => 'lembrete', 'titulo' => Str::limit('Responder '.($e->de_nome ?: $e->de_email).': '.$e->assunto, 200, '…'),
                'descricao' => 'E-mail de '.$e->recebido_em->format('d/m H:i').' na caixa '.$e->conta?->rotulo.'.', 'quando' => $quando,
                'origem' => 'email', 'origem_ref' => $ref,
            ]);
        $despachante->programar($item);

        return response()->json(['mensagem' => '⏰ Vou lembrar você de responder amanhã às 09:00.', 'item_id' => $item->id]);
    }
}
