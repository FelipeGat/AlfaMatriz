<?php

namespace App\Services\Secretario;

use App\Models\SecretarioItem;
use App\Models\SecretarioPreferencia;
use App\Models\SecretarioProposta;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Da frase ao item, sempre passando pela confirmação da pessoa (FR-008). A
 * proposta vale 30 min; só a mais recente da conta recebe "sim" ou correção.
 *
 * Nada aqui apaga ou altera OUTRO item a partir de texto: a frase só gera a
 * proposta dela (FR-011) — "me lembra de X e apague todos" vira o lembrete de
 * "X e apague todos", e nada mais.
 */
class Propostas
{
    private const SIM = '/^(sim|s|ok|okay|pode|confirma|confirmo|confirmado|isso|certo|beleza|blz|manda|fechado|perfeito)[.! ]*$/u';
    private const NAO = '/^(nao|n|cancela|cancelar|deixa|deixa pra la|esquece|desconsidera)[.! ]*$/u';

    public function __construct(private Interpretador $interpretador, private Despachante $despachante) {}

    /**
     * Interpreta uma frase nova. `null` = não é do Secretário (segue para a Central).
     *
     * @return array{proposta: ?SecretarioProposta, item: ?SecretarioItem, mensagem: string}|null
     */
    public function criar(User $user, string $texto, string $origem, bool $explicito = false): ?array
    {
        $r = $this->interpretador->interpretar($texto, CarbonImmutable::now(), $explicito);
        if ($r['resultado'] === Interpretador::NAO_E) {
            return null;
        }

        return DB::transaction(function () use ($user, $texto, $origem, $r) {
            // Uma pendente por vez: a frase nova substitui a anterior.
            SecretarioProposta::de($user)->where('status', SecretarioProposta::PENDENTE)->update(['status' => SecretarioProposta::DESCARTADA]);
            $p = SecretarioProposta::create([
                'user_id' => $user->id, 'origem' => $origem, 'texto' => $texto,
                'dados' => $r['dados'] + ['avisos' => $r['avisos'] ?? []],
                'falta' => $r['resultado'] === Interpretador::FALTA ? $r['falta'] : null,
                'expira_em' => now()->addMinutes(SecretarioProposta::VALIDADE_MIN),
            ]);

            return $this->depoisDeMontar($user, $p);
        });
    }

    /**
     * A mensagem é resposta à proposta pendente? ("sim", "não, às 10h",
     * "amanhã às 9h" depois de "quando?"). `null` = não é; segue o fluxo normal.
     *
     * @return array{proposta: ?SecretarioProposta, item: ?SecretarioItem, mensagem: string}|null
     */
    public function responder(User $user, string $texto): ?array
    {
        $p = SecretarioProposta::de($user)->abertas()->latest('id')->first();
        if (! $p) {
            return null;
        }
        $t = $this->normalizar($texto);

        if (preg_match(self::NAO, $t)) {
            $this->descartar($user, $p);

            return ['proposta' => null, 'item' => null, 'mensagem' => 'Ok, não registrei.'];
        }

        if ($p->falta === 'titulo') {
            // Frase com gatilho próprio é pedido novo, não a resposta.
            if ($this->interpretador->interpretar($texto, CarbonImmutable::now())['resultado'] !== Interpretador::NAO_E) {
                return null;
            }

            return DB::transaction(function () use ($user, $p, $texto) {
                $p->update(['dados' => ['titulo' => \Illuminate\Support\Str::limit(\Illuminate\Support\Str::ucfirst(trim($texto)), 200, '…')] + $p->dados, 'falta' => null]);

                return $this->depoisDeMontar($user, $p->fresh());
            });
        }

        if (preg_match(self::SIM, $t) && ! $p->falta) {
            return $this->confirmar($user, $p);
        }

        // "não, às 10h", "às 10h", "quinta às 15h": correção do quando.
        $semNao = preg_replace('/^(nao|na verdade|melhor|corrige|corrigindo)[,.: ]+/u', '', $t);
        // Só vale como correção se for CLARAMENTE sobre o horário: depois de
        // "quando?", começando por "não, …", ou só a expressão de tempo. Uma
        // pergunta qualquer com "amanhã" no meio segue para a Central.
        $soTempo = ! str_contains($t, '?') && mb_strlen($t) <= 30
            && preg_match('/^(as|amanha|hoje|depois|daqui|em|dia|na|no|segunda|terca|quarta|quinta|sexta|sabado|domingo|\d)/u', $t);
        if (($p->falta === 'quando' || $semNao !== $t || $soTempo) && ($q = $this->interpretador->soQuando($semNao, CarbonImmutable::now()))) {
            return DB::transaction(function () use ($user, $p, $q) {
                $dados = array_merge($p->dados, ['quando' => $q['quando'], 'so_data' => $q['so_data'], 'inequivoco' => false, 'avisos' => $q['avisos']]);
                if ($q['recorrencia']) {
                    $dados['recorrencia'] = $q['recorrencia'];
                }
                $p->update(['dados' => $dados, 'falta' => null, 'expira_em' => now()->addMinutes(SecretarioProposta::VALIDADE_MIN)]);

                return $this->depoisDeMontar($user, $p->fresh(), aceitarAutomatica: false);
            });
        }

        return null;
    }

    /** Monta a mensagem da proposta — ou grava direto, se a pessoa ligou a confirmação automática. */
    private function depoisDeMontar(User $user, SecretarioProposta $p, bool $aceitarAutomatica = true): array
    {
        if ($p->falta === 'quando') {
            // Pista de tempo que as regras não resolvem ("semana que vem,
            // depois da diretoria"): a IA da pessoa propõe, se a máquina está
            // ligada. Sem ela, pergunta direto (FR-007).
            $ia = app(PropostaDaIa::class);
            if ($p->texto !== 'reagendar' && ! $p->execucao_id && $ia->vale($user, $p->texto) && $ia->pedir($user, $p, $p->origem)) {
                return ['proposta' => $p, 'item' => null, 'mensagem' => '🤖 Não entendi o quando pelas regras; pedi à sua IA uma proposta. Ela chega aqui para você confirmar.'];
            }

            return ['proposta' => $p, 'item' => null, 'mensagem' => "🗓 Quando devo lembrar você de: {$p->dados['titulo']}?\nEx.: amanhã às 9h, daqui a 2 horas, sexta às 15h."];
        }
        if ($p->falta === 'titulo') {
            return ['proposta' => $p, 'item' => null, 'mensagem' => 'Lembrar de quê?'];
        }
        if ($aceitarAutomatica && ($p->dados['inequivoco'] ?? false) && ! ($p->dados['da_ia'] ?? false) && SecretarioPreferencia::de($user)->auto_confirmar) {
            $r = $this->confirmar($user, $p);
            $r['mensagem'] .= ' (confirmação automática — toque em Cancelar no item para desfazer)';

            return $r;
        }

        return ['proposta' => $p, 'item' => null, 'mensagem' => $this->resumo($user, $p->dados)];
    }

    public function resumo(User $user, array $d): string
    {
        $quando = $d['quando'] ? CarbonImmutable::parse($d['quando']) : null;
        $quandoTxt = $quando ? Datas::porExtenso($quando, (bool) $d['so_data']) : null;
        $serie = ! empty($d['recorrencia']) ? ' ('.Datas::recorrencia($d['recorrencia'], $quando).')' : '';
        $titulo = $d['titulo'];

        if (isset($d['item_id'])) {
            return "🗓 Reagendar “{$titulo}” para {$quandoTxt}. Confirma?";
        }
        $linha = match ($d['tipo']) {
            'compromisso' => "📝 Compromisso {$quandoTxt}{$serie}: {$titulo}.\nAviso ".$this->antecedencia($user).' min antes ('
                .$quando->copy()->subMinutes($this->antecedencia($user))->format('H:i').').',
            'tarefa' => $quando ? "📝 Tarefa para {$quandoTxt}{$serie}: {$titulo}.".($d['so_data'] ? ' Aviso às 08:00 do dia.' : '')
                : "📝 Vou anotar a tarefa: {$titulo} (sem data).",
            default => "📝 Vou lembrar você {$quandoTxt}{$serie} de: {$titulo}.".($d['so_data'] ? ' Aviso às 08:00 do dia.' : ''),
        };
        $notas = array_filter(array_map(fn ($a) => [
            'hoje_ja_passou' => 'Esse horário de hoje já passou; considerei amanhã.',
            'amanha_porque_passou' => 'Esse horário já passou hoje; considerei amanhã.',
            'ajustada' => 'Entendi como horário da tarde; se era de madrugada, corrija.',
            'proxima_semana' => 'Hoje já é esse dia da semana; considerei o da próxima semana.',
            'ano_seguinte' => 'Essa data já passou este ano; considerei o ano que vem.',
        ][$a] ?? null, $d['avisos'] ?? []));

        return $linha.($notas ? "\n⚠ ".implode(' ', $notas) : '')."\nConfirma?";
    }

    /** @return array{proposta: ?SecretarioProposta, item: ?SecretarioItem, mensagem: string} */
    public function confirmar(User $user, SecretarioProposta $proposta): array
    {
        return DB::transaction(function () use ($user, $proposta) {
            $p = SecretarioProposta::de($user)->whereKey($proposta->id)->lockForUpdate()->firstOrFail();
            if ($p->status === SecretarioProposta::CONFIRMADA && $p->item_id) {
                $item = SecretarioItem::de($user)->find($p->item_id);

                return ['proposta' => $p, 'item' => $item, 'mensagem' => '✓ Já estava registrado.'];
            }
            if ($p->status !== SecretarioProposta::PENDENTE || $p->expira_em->isPast() || $p->falta) {
                return ['proposta' => $p, 'item' => null, 'mensagem' => 'Essa proposta não vale mais. Diga de novo o que registrar.'];
            }
            $d = $p->dados;
            if (isset($d['item_id'])) {
                // Proposta de REAGENDAR um item que já existe.
                $item = SecretarioItem::de($user)->findOrFail($d['item_id']);
                $r = app(Itens::class)->reagendar($user, $item, ['quando' => $d['quando'], 'so_data' => (bool) $d['so_data']]);
                $p->update(['status' => SecretarioProposta::CONFIRMADA, 'item_id' => $item->id]);

                return ['proposta' => $p, 'item' => $r['item'], 'mensagem' => $r['mensagem']];
            }
            $quando = $d['quando'] ? CarbonImmutable::parse($d['quando']) : null;
            $recorrencia = $d['recorrencia'] ?? null;
            if ($recorrencia && $quando) {
                $recorrencia['hora'] = $quando->format('H:i');
            }
            $item = SecretarioItem::create([
                'user_id' => $user->id,
                'tipo' => in_array($d['tipo'], SecretarioItem::TIPOS, true) ? $d['tipo'] : 'lembrete',
                'titulo' => mb_substr($d['titulo'], 0, 200),
                'quando' => $quando,
                'so_data' => (bool) ($d['so_data'] ?? false),
                'antecedencia_min' => $d['tipo'] === 'compromisso' ? $this->antecedencia($user) : null,
                'recorrencia' => $recorrencia,
                'origem' => $p->origem === 'telegram' ? 'telegram' : 'matriz',
                'origem_ref' => 'proposta:'.$p->id,
            ]);
            $p->update(['status' => SecretarioProposta::CONFIRMADA, 'item_id' => $item->id]);
            $this->despachante->programar($item);
            Eventos::registrar($user, 'secretario_registrado', "Item #{$item->id} ({$item->tipo}) registrado.", ['origem' => $p->origem]);

            $quandoTxt = $item->quando ? ' para '.Datas::porExtenso($item->quando, $item->so_data) : '';

            return ['proposta' => $p, 'item' => $item, 'mensagem' => "✓ Registrado{$quandoTxt}: {$item->titulo}."];
        });
    }

    public function descartar(User $user, SecretarioProposta $proposta, bool $pedirCorrecao = false): void
    {
        SecretarioProposta::de($user)->whereKey($proposta->id)->where('status', SecretarioProposta::PENDENTE)
            ->update(['status' => SecretarioProposta::DESCARTADA]);
    }

    /** "Reagendar": abre uma proposta que espera só o novo quando do item. */
    public function pedirNovoQuando(User $user, SecretarioItem $item, string $origem): SecretarioProposta
    {
        return DB::transaction(function () use ($user, $item, $origem) {
            SecretarioProposta::de($user)->where('status', SecretarioProposta::PENDENTE)->update(['status' => SecretarioProposta::DESCARTADA]);

            return SecretarioProposta::create([
                'user_id' => $user->id, 'origem' => $origem, 'texto' => 'reagendar',
                'dados' => ['tipo' => $item->tipo, 'titulo' => $item->titulo, 'quando' => null, 'so_data' => false, 'recorrencia' => null,
                    'inequivoco' => false, 'item_id' => $item->id],
                'falta' => 'quando', 'expira_em' => now()->addMinutes(SecretarioProposta::VALIDADE_MIN),
            ]);
        });
    }

    private function antecedencia(User $user): int
    {
        return (int) SecretarioPreferencia::de($user)->antecedencia_padrao;
    }

    private function normalizar(string $t): string
    {
        return trim(strtr(mb_strtolower(trim($t)), ['ã' => 'a', 'á' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']));
    }
}
