#!/opt/whisper/bin/python
"""
Transcreve um áudio do Telegram para texto, em português, na própria máquina.

Usa o faster-whisper (Whisper em CTranslate2), modelo `small` quantizado em
int8: cabe nos 4 GB do LXC 108 e roda em CPU a cerca de tempo real, com erro
aceitável para pedidos curtos ditados ("pega a tarefa 228 e começa"). Não há
conta nem chave de serviço externo, e o áudio não sai da infra — decisão do
dono do produto em 30/09/2026, coerente com a de não abrir conta de API.

Chamado pela ponte (`ponte-telegram.mjs`) com o caminho do arquivo; imprime só
o texto. Qualquer erro vai para stderr e o código de saída diz que falhou —
a ponte mostra a frase e não manda nada ao Claude.

O modelo é carregado a cada chamada, de propósito: um processo residente
seguraria ~1 GB de RAM o dia inteiro para transcrever três áudios. Carregar
custa uns segundos, e são segundos que só se pagam quando alguém fala.
"""

import sys

from faster_whisper import WhisperModel

MODELO = "small"
PASTA_DOS_MODELOS = "/opt/whisper/modelos"


def main() -> int:
    if len(sys.argv) != 2:
        print("uso: transcrever.py <arquivo de áudio>", file=sys.stderr)
        return 2

    modelo = WhisperModel(MODELO, device="cpu", compute_type="int8", download_root=PASTA_DOS_MODELOS)

    # `vad_filter` corta os silêncios do começo e do fim, que num áudio de
    # Telegram são a maior parte do que o Whisper tende a alucinar ("Legendas
    # pela comunidade Amara.org" e afins).
    segmentos, _ = modelo.transcribe(sys.argv[1], language="pt", beam_size=5, vad_filter=True)

    texto = " ".join(s.text.strip() for s in segmentos).strip()

    if not texto:
        print("não entendi nada no áudio", file=sys.stderr)
        return 1

    print(texto)
    return 0


if __name__ == "__main__":
    sys.exit(main())
