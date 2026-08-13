<?php

namespace App\Concerns;

use App\Models\Auditoria;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Liga um modelo ao rastro de auditoria.
 *
 * Nos EVENTOS do Eloquent, e não em chamadas dentro dos controllers, porque o
 * mesmo registro é escrito de vários lugares: cliente nasce no modal da lista,
 * no sincronizador do AlfaGym e no provisionamento de revenda. Um `registrar()`
 * por controller seria correto no dia em que fosse escrito e ficaria para trás
 * no primeiro caminho novo — e o buraco não apareceria como erro, apareceria
 * como um dia sem movimento na tela de auditoria.
 *
 * Quem usa declara `$recursoAuditoria` com o MESMO nome do recurso de permissão
 * (`clientes`, `cobrancas`, `financeiro`…): é o que faz a tela de auditoria
 * filtrar pelo vocabulário que o resto do painel já usa.
 */
trait Auditavel
{
    public static function bootAuditavel(): void
    {
        static::created(fn ($modelo) => $modelo->registrarAuditoria('criou'));

        // `updated` e não `saved`: `saved` dispara também na criação, e cada
        // cadastro novo geraria duas linhas dizendo a mesma coisa.
        static::updated(fn ($modelo) => $modelo->registrarAuditoria('alterou'));

        static::deleted(fn ($modelo) => $modelo->registrarAuditoria('excluiu'));

        // `restored` NÃO é evento de todo modelo: quem o declara é o
        // `SoftDeletes`, e não o `HasEvents` como os três de cima. Registrar
        // sem perguntar derruba na hora todo modelo sem exclusão reversível —
        // categoria, conta, centro de custo — com um "método não existe" vindo
        // de dentro do boot, longe de qualquer pista de auditoria.
        //
        // A pergunta é pelo método, e não pelo trait, para que qualquer outra
        // forma de restauração que o Laravel introduza continue valendo.
        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($modelo) => $modelo->registrarAuditoria('restaurou'));
        }
    }

    /**
     * O rastro deste registro. Sem chave estrangeira do outro lado, então a
     * relação serve para ler — nunca para `cascade`.
     */
    public function auditorias(): MorphMany
    {
        return $this->morphMany(Auditoria::class, 'auditavel');
    }

    /**
     * Como este registro se apresenta numa linha de auditoria.
     *
     * O padrão cobre a maioria dos modelos do painel; quem não tem nenhuma
     * dessas colunas sobrescreve. O fallback com a classe e o id é feio de
     * propósito — é para ser percebido e corrigido, não para passar despercebido
     * como uma linha vazia.
     */
    public function descricaoDeAuditoria(): string
    {
        foreach (['nome', 'titulo', 'descricao', 'nome_fantasia', 'razao_social'] as $campo) {
            $valor = $this->getAttribute($campo);

            if (is_string($valor) && trim($valor) !== '') {
                return $valor;
            }
        }

        return class_basename($this).' #'.$this->getKey();
    }

    /**
     * Campos que nunca entram no antes/depois deste modelo.
     *
     * Separado dos ocultos (abaixo) porque a diferença importa: aqui o campo
     * some, lá ele aparece marcado. Some o que é ruído — carimbo de tempo,
     * contador recalculado —, e some porque uma linha "atualizado_em mudou" a
     * cada salvamento afogaria a mudança que interessa.
     *
     * @return array<int, string>
     */
    public function camposForaDaAuditoria(): array
    {
        return [];
    }

    /**
     * Campos cujo valor não é gravado, só a marca de que mudaram.
     *
     * @return array<int, string>
     */
    public function camposOcultosNaAuditoria(): array
    {
        return ['password'];
    }

    public function registrarAuditoria(string $acao): void
    {
        $alteracoes = match ($acao) {
            // No cadastro o "antes" não existe, e no descarte não existe o
            // "depois". Guardar o registro inteiro na exclusão é o ponto: é a
            // última cópia do que havia ali, e é a linha que alguém vai
            // procurar quando perguntar o que foi perdido.
            'criou' => $this->parDeAuditoria($this->getAttributes(), depois: true),
            'excluiu' => $this->parDeAuditoria($this->getAttributes(), depois: false),
            default => $this->diferencaDeAuditoria(),
        };

        // Salvamento que não mudou nada dispara `updated` do mesmo jeito — o
        // formulário reenviado sem edição é o caso comum. Sem esta saída, a
        // tela de auditoria se encheria de "Alterou" sem nada alterado.
        if ($acao === 'alterou' && $alteracoes === []) {
            return;
        }

        Auditoria::registrar(
            recurso: $this->recursoAuditoria,
            acao: $acao,
            alvo: $this,
            descricao: $this->descricaoDeAuditoria(),
            alteracoes: $alteracoes,
        );
    }

    /**
     * O que mudou neste salvamento, no formato `campo => [de, para]`.
     *
     * @return array<string, array{de: mixed, para: mixed}>
     */
    private function diferencaDeAuditoria(): array
    {
        $par = [];

        foreach ($this->getChanges() as $campo => $depois) {
            if ($this->foraDaAuditoria($campo)) {
                continue;
            }

            $oculto = in_array($campo, $this->camposOcultosNaAuditoria(), true);

            // `getRawOriginal`, e não `getOriginal`: o valor CRU da coluna, sem
            // passar pelos casts. Os dois lados precisam vir da mesma régua —
            // `getChanges()` já devolve o cru, e misturar as duas faria a mesma
            // troca aparecer como `800.00 → 400`, com o antes formatado pelo
            // cast decimal e o depois não. Cru também é o que mantém data
            // legível: `2026-08-15`, e não o ISO completo que o Carbon produz.
            $par[$campo] = [
                'de' => $oculto ? Auditoria::OCULTO : $this->getRawOriginal($campo),
                'para' => $oculto ? Auditoria::OCULTO : $depois,
            ];
        }

        return $par;
    }

    /**
     * Um registro inteiro visto como antes/depois — o lado vazio fica nulo.
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, array{de: mixed, para: mixed}>
     */
    private function parDeAuditoria(array $atributos, bool $depois): array
    {
        $par = [];

        foreach ($atributos as $campo => $valor) {
            if ($this->foraDaAuditoria($campo)) {
                continue;
            }

            if (in_array($campo, $this->camposOcultosNaAuditoria(), true)) {
                $valor = Auditoria::OCULTO;
            }

            $par[$campo] = $depois ? ['de' => null, 'para' => $valor] : ['de' => $valor, 'para' => null];
        }

        return $par;
    }

    private function foraDaAuditoria(string $campo): bool
    {
        // Os carimbos do Eloquent saem para todo mundo: eles mudam em todo
        // salvamento e nunca são a resposta à pergunta "o que mudou aqui?".
        // `deleted_at` sai junto porque a exclusão já é a AÇÃO da linha —
        // registrá-la também como campo alterado diria a mesma coisa duas
        // vezes, uma delas em formato de data crua.
        //
        // `remember_token` sai junto, e por um motivo diferente: ele é trocado
        // toda vez que alguém entra marcando "lembrar de mim", e uma linha
        // "alterou usuário" a cada login enterraria a mudança de perfil que
        // aconteceu no meio delas. Ele não é fato de negócio nenhum — é
        // ferragem de sessão.
        $sempre = [
            $this->getKeyName(),
            'created_at', 'updated_at', 'deleted_at', 'remember_token',
        ];

        return in_array($campo, $sempre, true)
            || in_array($campo, $this->camposForaDaAuditoria(), true);
    }
}
