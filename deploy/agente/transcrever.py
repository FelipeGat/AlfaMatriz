#!/usr/bin/env python3
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

import os
import sys

import av
import numpy as np
from faster_whisper import WhisperModel

MODELO = "small"
# Onde o modelo já está baixado. Muda de máquina para máquina (LXC: /opt/whisper;
# Mac: a casa do usuário do agente), e o serviço diz qual pelo ambiente. O
# `python3` do shebang também vem do ambiente: quem inicia a ponte põe o `bin`
# do virtualenv na frente do PATH, e é esse python que tem o faster-whisper.
PASTA_DOS_MODELOS = os.environ.get("WHISPER_MODELOS", "/opt/whisper/modelos")


def decodificar(caminho: str) -> np.ndarray:
    """
    O áudio como o Whisper o quer: mono, 16 kHz, float32 entre -1 e 1.

    Feito aqui, e não pelo decodificador do faster-whisper, porque o dele chama
    `av.open(..., metadata_errors=...)`, argumento que o PyAV 15+ removeu — e o
    `pip` instala o PyAV mais novo. Fixar a versão resolveu no LXC e não
    resolveu no Mac, onde o Python 3.14 só tem pacote pronto do PyAV 15 em
    diante. Decodificando por conta própria, a versão deixa de importar.
    """
    reamostrador = av.AudioResampler(format="s16", layout="mono", rate=16000)
    pedacos = []

    with av.open(caminho) as arquivo:
        for quadro in arquivo.decode(audio=0):
            pedacos.extend(r.to_ndarray().reshape(-1) for r in reamostrador.resample(quadro))

        # O reamostrador segura o fim do áudio até ser esvaziado.
        pedacos.extend(r.to_ndarray().reshape(-1) for r in reamostrador.resample(None))

    if not pedacos:
        return np.zeros(0, dtype=np.float32)

    return np.concatenate(pedacos).astype(np.float32) / 32768.0


def main() -> int:
    if len(sys.argv) != 2:
        print("uso: transcrever.py <arquivo de áudio>", file=sys.stderr)
        return 2

    modelo = WhisperModel(MODELO, device="cpu", compute_type="int8", download_root=PASTA_DOS_MODELOS)

    # `vad_filter` corta os silêncios do começo e do fim, que num áudio de
    # Telegram são a maior parte do que o Whisper tende a alucinar ("Legendas
    # pela comunidade Amara.org" e afins).
    segmentos, _ = modelo.transcribe(decodificar(sys.argv[1]), language="pt", beam_size=5, vad_filter=True)

    texto = " ".join(s.text.strip() for s in segmentos).strip()

    if not texto:
        print("não entendi nada no áudio", file=sys.stderr)
        return 1

    print(texto)
    return 0


if __name__ == "__main__":
    sys.exit(main())
