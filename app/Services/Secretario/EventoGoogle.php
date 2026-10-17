<?php

namespace App\Services\Secretario;

use App\Models\SecretarioGoogleAgenda;
use App\Models\SecretarioGoogleConta;
use App\Models\SecretarioGoogleEvento;
use App\Models\SecretarioItem;
use App\Models\SecretarioPreferencia;
use App\Models\SecretarioProposta;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use Carbon\CarbonImmutable;

/**
 * "Agenda uma reunião com o Alexandre segunda às 14h" → evento no Google
 * (spec US4, FR-013–FR-016). Ação EXTERNA: só sai depois da confirmação da
 * pessoa, sem convidados (ninguém de fora recebe nada), e "agendado" só é
 * dito depois de o Google responder que criou.
 */
class EventoGoogle
{
    public function __construct(private GoogleAgenda $google, private AgendaConsolidada $agenda, private Despachante $despachante) {}

    public function disponivel(User $user): bool
    {
        return SecretarioGoogleConta::where('user_id', $user->id)->where('situacao', SecretarioGoogleConta::CONECTADA)->exists();
    }

    /** A agenda: a primeira cujas palavras aparecem na frase; senão a padrão; senão a primária. */
    public function escolherAgenda(User $user, string $texto, array $dados): array
    {
        $t = mb_strtolower($texto);
        $agendas = SecretarioGoogleAgenda::where('user_id', $user->id)->orderBy('id')->get();
        $porPalavra = $agendas->first(fn ($a) => collect($a->listaDePalavras())->contains(fn ($p) => $p !== '' && str_contains($t, $p))
            || ($a->nome !== '' && str_contains($t, mb_strtolower($a->nome))));
        $a = $porPalavra ?? $agendas->firstWhere('padrao', true) ?? $agendas->firstWhere('primaria', true) ?? $agendas->first();

        return $dados + ['agenda_id' => $a?->id, 'agenda_nome' => $a?->nome];
    }

    public function resumo(User $user, array $d): string
    {
        $inicio = CarbonImmutable::parse($d['quando']);
        $fim = $d['fim'] ? CarbonImmutable::parse($d['fim']) : $inicio->addHour();
        $agenda = $d['agenda_nome'] ?? '(nenhuma agenda)';
        $conflitos = $this->agenda->periodo($user, $inicio->startOfDay(), $inicio->endOfDay())
            ->filter(fn ($i) => $i['ocupa'] && $i['inicio']->lessThan($fim) && $inicio->lessThan($i['fim']));
        $linha = "📅 Criar no Google, agenda {$agenda}, ".Datas::porExtenso($inicio).'–'.$fim->format('H:i').": {$d['titulo']}.\nSem convidados (ninguém recebe convite).";
        if ($conflitos->isNotEmpty()) {
            $linha .= "\n⚠ Conflita com: ".$conflitos->map(fn ($i) => $i['inicio']->format('H:i').' '.$i['titulo'].' ('.$i['origem_rotulo'].')')->implode('; ').'.';
        }
        if ($aviso = $this->agenda->aviso($this->agenda->situacaoGoogle($user))) {
            $linha .= "\n".$aviso;
        }

        return $linha."\nConfirma?";
    }

    /**
     * Cria no Google. Chamado com a proposta TRAVADA (Propostas::confirmar):
     * falha deixa a proposta pendente para tentar de novo; sucesso a fecha.
     *
     * @return array{proposta: SecretarioProposta, item: ?SecretarioItem, mensagem: string}
     */
    public function criar(User $user, SecretarioProposta $p): array
    {
        $d = $p->dados;
        $conta = SecretarioGoogleConta::where('user_id', $user->id)->first();
        $agenda = SecretarioGoogleAgenda::where('user_id', $user->id)->find($d['agenda_id'] ?? 0);
        if (! $conta || ! $conta->conectada() || ! $agenda) {
            return ['proposta' => $p, 'item' => null, 'mensagem' => '❌ Não agendei: o Google Agenda não está conectado (Secretário → Configurações).'];
        }
        $inicio = CarbonImmutable::parse($d['quando']);
        $fim = $d['fim'] ? CarbonImmutable::parse($d['fim']) : $inicio->addHour();
        $tz = config('app.timezone');

        $r = $this->google->post($conta, '/calendars/'.rawurlencode($agenda->google_id).'/events', [
            'summary' => mb_substr($d['titulo'], 0, 200),
            'start' => ['dateTime' => $inicio->toRfc3339String(), 'timeZone' => $tz],
            'end' => ['dateTime' => $fim->toRfc3339String(), 'timeZone' => $tz],
            // Sem `attendees` (FR-016): o evento é só da agenda da própria pessoa.
        ], ['sendUpdates' => 'none']);

        if (! $r['ok'] || ! is_string($r['dados']['id'] ?? null)) {
            $motivo = [
                'nao_enviada' => 'o Google não respondeu (rede)', 'reconectar' => 'a conta Google precisa ser reconectada',
                'nao_autorizado' => 'o Google recusou o acesso', 'limite' => 'o Google pediu para esperar um pouco',
                'recusada' => 'o Google recusou o pedido',
            ][$r['erro'] ?? ''] ?? 'resposta incerta do Google — confira na agenda antes de tentar de novo';
            Eventos::registrar($user, 'secretario_evento_falhou', "Evento da proposta #{$p->id} não criado no Google ({$r['erro']}).", ['origem' => 'google'], 'aviso');

            return ['proposta' => $p, 'item' => null, 'mensagem' => "❌ Não agendei: {$motivo}. Responda sim para tentar de novo."];
        }

        $id = mb_substr($r['dados']['id'], 0, 255);
        $link = is_string($r['dados']['htmlLink'] ?? null) && str_starts_with($r['dados']['htmlLink'], 'https://') ? mb_substr($r['dados']['htmlLink'], 0, 500) : null;
        // Já no cache: aparece na visão sem esperar a próxima sincronização.
        SecretarioGoogleEvento::updateOrCreate(['agenda_id' => $agenda->id, 'google_id' => $id], [
            'user_id' => $user->id, 'titulo' => mb_substr($d['titulo'], 0, 255), 'inicio' => $inicio, 'fim' => $fim, 'dia_inteiro' => false, 'link' => $link,
        ]);
        // O aviso antes da reunião vem do Secretário (Etapa 1).
        $item = SecretarioItem::create([
            'user_id' => $user->id, 'tipo' => 'compromisso', 'titulo' => mb_substr($d['titulo'], 0, 200), 'quando' => $inicio,
            'antecedencia_min' => (int) SecretarioPreferencia::de($user)->antecedencia_padrao,
            'origem' => 'google', 'origem_ref' => "google:{$agenda->id}:{$id}",
        ]);
        $this->despachante->programar($item);
        $p->update(['status' => SecretarioProposta::CONFIRMADA, 'item_id' => $item->id]);
        Eventos::registrar($user, 'secretario_evento_criado', "Evento criado no Google (item #{$item->id}).", ['origem' => 'google']);

        return ['proposta' => $p, 'item' => $item, 'mensagem' => "✓ Agendado no Google ({$agenda->nome}): ".Datas::porExtenso($inicio).'–'.$fim->format('H:i')." — {$d['titulo']}."];
    }
}
