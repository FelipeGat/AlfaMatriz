<?php

namespace App\Services\MercadoPago;

use App\Models\Cobranca;
use App\Models\Notificacao;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * O boleto da revenda "por cliente" (docs/specs/cobranca-revendas-boleto):
 * emite pelo Mercado Pago e confere o pagamento pela consulta — a baixa só
 * acontece com o que o Mercado Pago responde, uma vez (FR-002, FR-003).
 */
class BoletoDaRevenda
{
    /** Limites do boleto no Mercado Pago (os mesmos do Gestor). */
    public const VALOR_MINIMO = 3.00;

    public const DIAS_MAXIMOS = 28;

    public function __construct(private MercadoPago $mp) {}

    /** As cobranças que a rotina diária emite. */
    public function paraEmitir()
    {
        return Cobranca::with('revenda')->where('tipo', 'locacao_sistema')->where('status', 'pendente')->whereNull('mp_payment_id')
            ->whereHas('revenda', fn ($q) => $q->where('cobranca_por_cliente', true))->orderBy('id')->get();
    }

    /** As que a conferência de hora em hora consulta. */
    public function paraConferir()
    {
        return Cobranca::where('tipo', 'locacao_sistema')->where('status', 'pendente')->whereNotNull('mp_payment_id')->orderBy('id')->get();
    }

    /** Por que esta cobrança não pode emitir agora, ou null. */
    public function impedimento(Cobranca $c, ?CarbonImmutable $hoje = null): ?string
    {
        $hoje ??= CarbonImmutable::today();
        $revenda = $c->revenda;

        return match (true) {
            ! $this->mp->configurado() => 'O Mercado Pago não está configurado no servidor.',
            $c->status !== 'pendente' || $c->tipo !== 'locacao_sistema' => 'Só cobrança de licenciamento pendente emite boleto.',
            ! $revenda?->cobranca_por_cliente => 'A revenda não está na cobrança por cliente.',
            filled($c->mp_payment_id) => 'Esta cobrança já tem boleto.',
            (float) $c->valor < self::VALOR_MINIMO => 'Valor abaixo do mínimo do boleto (R$ 3,00).',
            ! $c->data_vencimento || $c->data_vencimento->lt($hoje->addDay()) => 'O vencimento já passou ou é hoje: ajuste o vencimento antes de emitir.',
            $c->data_vencimento->gt($hoje->addDays(self::DIAS_MAXIMOS)) => 'O boleto do Mercado Pago vence em até 28 dias: ele sai mais perto do vencimento.',
            (bool) ($falta = $revenda->faltaParaBoleto()) => 'Falta na revenda: '.implode(', ', $falta).'.',
            default => null,
        };
    }

    /** Emite o boleto. Devolve a cobrança atualizada; lança `ErroDoMercadoPago` com a mensagem para a tela. */
    public function emitir(Cobranca $cobranca): Cobranca
    {
        return DB::transaction(function () use ($cobranca) {
            // Travada: duas emissões simultâneas (rotina e botão) esperam aqui, e a
            // segunda acha o boleto já gravado.
            $c = Cobranca::with('revenda')->whereKey($cobranca->id)->lockForUpdate()->firstOrFail();
            if ($motivo = $this->impedimento($c)) {
                throw new ErroDoMercadoPago($motivo);
            }
            $r = $c->revenda;
            $nome = trim($r->nome);
            [$primeiro, $resto] = array_pad(explode(' ', $nome, 2), 2, '');
            $pagamento = $this->mp->criarPagamento([
                'transaction_amount' => round((float) $c->valor, 2),
                'description' => mb_substr($c->descricao, 0, 250),
                'payment_method_id' => 'bolbradesco',
                'date_of_expiration' => CarbonImmutable::parse($c->data_vencimento->toDateString(), 'America/Sao_Paulo')->endOfDay()->format('Y-m-d\TH:i:s.000P'),
                'external_reference' => 'MATRIZ-COBRANCA-'.$c->id,
                'payer' => [
                    'email' => $r->email_financeiro,
                    'first_name' => $primeiro,
                    'last_name' => $resto !== '' ? $resto : $primeiro,
                    'identification' => ['type' => 'CNPJ', 'number' => preg_replace('/\D/', '', $r->cnpj)],
                    'address' => [
                        'zip_code' => preg_replace('/\D/', '', $r->cep),
                        'street_name' => $r->logradouro,
                        'street_number' => $r->numero ?: 'S/N',
                        'neighborhood' => $r->bairro ?: 'Centro',
                        'city' => $r->cidade,
                        'federal_unit' => $r->uf,
                    ],
                ],
            ], 'matriz-cobranca-'.$c->id);

            $c->forceFill([
                'mp_payment_id' => (string) ($pagamento['id'] ?? ''),
                'mp_status' => $pagamento['status'] ?? null,
                'mp_status_detail' => $pagamento['status_detail'] ?? null,
                'mp_ticket_url' => self::https($pagamento['transaction_details']['external_resource_url'] ?? null),
                'mp_linha_digitavel' => $pagamento['transaction_details']['digitable_line'] ?? null,
                'mp_codigo_barras' => $pagamento['barcode']['content'] ?? null,
                'mp_emitido_em' => now(),
                'mp_erro' => null,
                'forma_pagamento' => 'boleto',
            ])->save();
            Log::info('Boleto emitido no Mercado Pago', ['cobranca' => $c->id, 'pagamento' => $c->mp_payment_id]);
            $this->avisar('Boleto emitido: '.$r->nome, 'R$ '.number_format((float) $c->valor, 2, ',', '.').' · vence '.$c->data_vencimento->format('d/m'), 'banknotes', 'marca');

            return $c;
        });
    }

    /** Consulta o pagamento e dá baixa se aprovado. Devolve o status do Mercado Pago. */
    public function conferir(Cobranca $cobranca): string
    {
        $p = $this->mp->pagamento($cobranca->mp_payment_id);
        $status = (string) ($p['status'] ?? 'desconhecido');

        DB::transaction(function () use ($cobranca, $p, $status) {
            $c = Cobranca::with('revenda')->whereKey($cobranca->id)->lockForUpdate()->firstOrFail();
            $antes = $c->mp_status;
            $c->forceFill(['mp_status' => $status, 'mp_status_detail' => $p['status_detail'] ?? null, 'mp_conferido_em' => now()])->save();
            if ($status === 'approved' && $c->status === 'pendente') {
                if (! $c->conta_financeira_id && $c->revenda?->conta_financeira_id) {
                    $c->forceFill(['conta_financeira_id' => $c->revenda->conta_financeira_id])->save();
                }
                $valor = (float) ($p['transaction_details']['total_paid_amount'] ?? $p['transaction_amount'] ?? $c->valor);
                $data = isset($p['date_approved']) ? CarbonImmutable::parse($p['date_approved'])->setTimezone(config('app.timezone'))->toDateString() : now()->toDateString();
                $c->baixar($valor, $data);
                if ($c->fresh()->status === 'pago') {
                    Log::info('Boleto pago e baixado', ['cobranca' => $c->id]);
                    $this->avisar('Boleto pago: '.$c->revenda?->nome, 'R$ '.number_format($valor, 2, ',', '.').' em '.CarbonImmutable::parse($data)->format('d/m').' · baixa feita no Caixa', 'check-circle', 'marca');
                } else {
                    $this->avisar('Boleto pago, mas sem baixa: '.$c->revenda?->nome, 'Falta a conta do Caixa na cobrança #'.$c->id, 'alert-triangle', 'critico');
                }
            } elseif (in_array($status, ['cancelled', 'rejected', 'refunded', 'charged_back'], true) && $antes !== $status) {
                $this->avisar('Boleto '.$status.': '.$c->revenda?->nome, 'Cobrança #'.$c->id.' — confira e emita de novo se for o caso.', 'alert-triangle', 'critico');
            }
        });

        return $status;
    }

    /** Avisa no sino quem tem a cobrança de revendas. */
    private function avisar(string $titulo, string $meta, string $icone, string $nivel): void
    {
        foreach (User::idsDeQuemVe('cobranca_revendas', 'ler') as $id) {
            Notificacao::avisar($id, null, ['tipo' => 'cobranca_revendas', 'nivel' => $nivel, 'icone' => $icone, 'titulo' => $titulo, 'meta' => $meta,
                'rota' => route('cobranca-revendas.index')]);
        }
    }

    private static function https(?string $url): ?string
    {
        return $url && str_starts_with($url, 'https://') ? mb_substr($url, 0, 500) : null;
    }
}
