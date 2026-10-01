#!/bin/bash
#
# Gera o token de longa duração do Claude Code para o agente e o guarda no
# chaveiro do macOS — sem ninguém copiar e colar nada.
#
#   bash deploy/agente/guardar-token-claude.sh
#
# Por que existe: o `claude setup-token` mostra um token de ~108 caracteres numa
# tela que quebra a linha, e copiá-lo à mão perdeu um caractere em 01/10/2026 —
# o token chegou ao chaveiro com o prefixo certo e a Anthropic o recusou. Aqui o
# comando roda num terminal largo o bastante para o token não quebrar, a saída é
# capturada, e o token só é guardado depois de a API dizer que ele vale.
#
# O usuário do agente não tem chaveiro (nunca entrou pela tela), e por isso o
# `/login` comum não serve para ele: o token vai para o ambiente do serviço, em
# `CLAUDE_CODE_OAUTH_TOKEN`.

set -euo pipefail

SERVICO=alfa-claude-agente
CONTA=oauth
SAIDA="$(mktemp)"
trap 'rm -f "$SAIDA"' EXIT

echo "Vou abrir o navegador para você autorizar. Depois volte aqui."
echo

# `stty cols 250` dentro do pty do `script`: a tela do Claude Code deixa de
# quebrar o token em duas linhas, e o que fica gravado é a linha inteira.
script -q "$SAIDA" /bin/sh -c 'stty cols 250 2>/dev/null; exec claude setup-token'

# Tira as sequências de cor e cursor, e procura o token. Duas leituras: a linha
# como veio, e a linha emendada com a seguinte (caso a tela ainda tenha quebrado).
LIMPO="$(perl -pe 's/\e\[[0-9;?]*[a-zA-Z]//g; s/\e\][^\a]*\a//g; s/\r//g' "$SAIDA")"

CANDIDATOS="$(
    printf '%s\n' "$LIMPO" | grep -oE 'sk-ant-oat01-[A-Za-z0-9_-]+' || true
    printf '%s' "$LIMPO" | perl -0pe 's/\n[ \t]*//g' | grep -oE 'sk-ant-oat01-[A-Za-z0-9_-]+AA' || true
)"

if [[ -z "$CANDIDATOS" ]]; then
    echo "Não achei o token na saída do comando. A autorização terminou?" >&2
    exit 1
fi

vale() {
    curl -s --max-time 20 'https://api.anthropic.com/v1/models?limit=1' \
        -H "Authorization: Bearer $1" \
        -H 'anthropic-beta: oauth-2025-04-20' \
        -H 'anthropic-version: 2023-06-01' | grep -q '"data"'
}

while IFS= read -r CANDIDATO; do
    [[ -n "$CANDIDATO" ]] || continue
    if vale "$CANDIDATO"; then
        security add-generic-password -U -s "$SERVICO" -a "$CONTA" -w "$CANDIDATO"
        echo
        echo "Token conferido com a Anthropic e guardado no chaveiro (${#CANDIDATO} caracteres)."
        exit 0
    fi
done <<< "$CANDIDATOS"

echo "Achei o token, mas a Anthropic não o aceitou. Rode de novo." >&2
exit 1
