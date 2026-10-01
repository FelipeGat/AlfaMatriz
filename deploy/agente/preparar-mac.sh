#!/bin/bash
#
# Prepara um Mac para hospedar o agente do AlfaMatriz num usuário SEPARADO.
# Roda uma vez, como administrador:
#
#   sudo bash deploy/agente/preparar-mac.sh
#
# Por que um usuário separado: o agente roda o Claude Code sem pedir permissão a
# cada passo. No usuário de quem trabalha na máquina, isso daria a ele a chave
# SSH do Proxmox, o chaveiro e todos os repositórios. Num usuário próprio, sem
# nada disso, o pior caso volta a ser o clone dele — o mesmo isolamento que o
# LXC dava, com o SSD que o LXC não tem. Decisão do dono do produto em
# 01/10/2026, depois de medir o disco do servidor saturado.
#
# Este script faz SÓ o que exige administrador, e nada além:
#   1. cria o usuário `agente` (comum, sem login na tela, senha aleatória);
#   2. deixa quem rodou o sudo agir COMO o agente sem senha — e controlar o
#      serviço dele —, por uma regra de sudoers restrita a isso;
#   3. registra o serviço no launchd, sem ligá-lo.
# Clone, dependências, tokens e o primeiro start ficam para depois, já sem sudo.

set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Rode com sudo: sudo bash $0" >&2
    exit 1
fi

DONO="${SUDO_USER:?rode com sudo a partir do seu usuário, não como root direto}"
USUARIO=agente
CASA="/Users/${USUARIO}"
ROTULO=br.com.alfa.agente
PLIST="/Library/LaunchDaemons/${ROTULO}.plist"

# ---------- 1. o usuário ----------

if id "$USUARIO" >/dev/null 2>&1; then
    echo "Usuário ${USUARIO} já existe."
else
    # A senha é aleatória e não é guardada: ninguém entra nesta conta pela
    # tela nem por SSH. Chega-se a ela só por `sudo -u agente`, a partir do
    # usuário do dono.
    sysadminctl -addUser "$USUARIO" -fullName "Agente AlfaMatriz" \
        -password "$(openssl rand -base64 30)" -home "$CASA" >/dev/null 2>&1
    createhomedir -c -u "$USUARIO" >/dev/null 2>&1 || true
    dscl . create "/Users/${USUARIO}" IsHidden 1
    echo "Usuário ${USUARIO} criado."
fi

mkdir -p "${CASA}/alfa-agente"
chown -R "${USUARIO}:staff" "${CASA}/alfa-agente"
chmod 700 "${CASA}/alfa-agente"

# ---------- 2. a regra de sudoers ----------

# Duas permissões, e só elas: agir como o agente, e ligar/desligar/reiniciar o
# serviço dele. Nada de root genérico.
REGRA="/etc/sudoers.d/alfa-agente"
TMP="$(mktemp)"
cat > "$TMP" <<EOF
${DONO} ALL=(${USUARIO}) NOPASSWD: ALL
${DONO} ALL=(root) NOPASSWD: /bin/launchctl bootstrap system ${PLIST}, /bin/launchctl bootout system/${ROTULO}, /bin/launchctl kickstart -k system/${ROTULO}, /bin/launchctl print system/${ROTULO}
EOF
visudo -cf "$TMP" >/dev/null
install -m 440 -o root -g wheel "$TMP" "$REGRA"
rm -f "$TMP"
echo "Regra de sudoers em ${REGRA}."

# ---------- 3. o serviço ----------

cat > "$PLIST" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>${ROTULO}</string>
    <key>UserName</key><string>${USUARIO}</string>
    <key>ProgramArguments</key>
    <array>
        <string>/bin/bash</string>
        <string>${CASA}/alfa-agente/iniciar.sh</string>
    </array>
    <key>EnvironmentVariables</key>
    <dict>
        <key>HOME</key><string>${CASA}</string>
    </dict>
    <key>WorkingDirectory</key><string>${CASA}</string>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>ThrottleInterval</key><integer>10</integer>
    <key>ProcessType</key><string>Background</string>
    <key>StandardOutPath</key><string>${CASA}/alfa-agente/ponte.log</string>
    <key>StandardErrorPath</key><string>${CASA}/alfa-agente/ponte.log</string>
</dict>
</plist>
EOF
chown root:wheel "$PLIST"
chmod 644 "$PLIST"
echo "Serviço registrado em ${PLIST} (ainda desligado)."

echo
echo "Pronto. O resto não precisa mais de senha de administrador."
