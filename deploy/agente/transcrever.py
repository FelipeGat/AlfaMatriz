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

Dois modos. Com um caminho, transcreve e sai — o modelo é carregado a cada
chamada, que é o certo onde a memória é curta (o LXC de 4 GB): um processo
residente seguraria ~1 GB o dia inteiro para transcrever três áudios. Com
`--servidor`, fica aberto com o modelo na memória e atende um caminho por
linha — o certo onde há memória (o Mac), porque carregar o modelo é mais da
metade do tempo de um áudio curto.
"""

import json
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


# Os nomes que o Whisper não tem como adivinhar. Sem isto, "AlfaGym" virou
# "Alphagene" e "Alphagin" no mesmo áudio de teste — e é o nome do sistema que
# decide em que quadro a tarefa cai. O `initial_prompt` não é instrução: é só
# texto que o modelo trata como "o que veio antes", e por isso puxa a grafia.
VOCABULARIO = os.environ.get(
    "WHISPER_VOCABULARIO",
    "AlfaMatriz, AlfaGym, AlfaControl, AlfaHome, AlfaJornada, AlfaMed, AlfaMonitor, "
    "Gestor Alfa, AlfaMobi. Tarefa, quadro, agenda, triagem, backlog, staging, produção, deploy.",
)


def carregar() -> WhisperModel:
    return WhisperModel(MODELO, device="cpu", compute_type="int8", download_root=PASTA_DOS_MODELOS)


def ouvir(modelo: WhisperModel, caminho: str) -> str:
    # `vad_filter` corta os silêncios do começo e do fim, que num áudio de
    # Telegram são a maior parte do que o Whisper tende a alucinar ("Legendas
    # pela comunidade Amara.org" e afins).
    segmentos, _ = modelo.transcribe(
        decodificar(caminho), language="pt", beam_size=5, vad_filter=True, initial_prompt=VOCABULARIO,
    )

    return " ".join(s.text.strip() for s in segmentos).strip()


def servir() -> int:
    """
    O modo residente: o modelo carrega uma vez, e cada linha da entrada é um
    caminho de áudio. Uma linha de JSON por resposta. A ponte o usa onde há
    memória para manter ~1 GB ocupado (o Mac); a carga do modelo, que é mais
    da metade do tempo de um áudio curto, deixa de se repetir a cada mensagem.
    """
    modelo = carregar()
    print(json.dumps({"pronto": True}), flush=True)

    for linha in sys.stdin:
        caminho = linha.strip()
        if not caminho:
            continue
        try:
            texto = ouvir(modelo, caminho)
            print(json.dumps({"texto": texto} if texto else {"erro": "não entendi nada no áudio"}), flush=True)
        except Exception as e:  # noqa: BLE001 — um áudio ruim não pode derrubar o servidor
            print(json.dumps({"erro": str(e)}), flush=True)

    return 0


def main() -> int:
    if len(sys.argv) != 2:
        print("uso: transcrever.py <arquivo de áudio> | --servidor", file=sys.stderr)
        return 2

    if sys.argv[1] == "--servidor":
        return servir()

    texto = ouvir(carregar(), sys.argv[1])

    if not texto:
        print("não entendi nada no áudio", file=sys.stderr)
        return 1

    print(texto)
    return 0


if __name__ == "__main__":
    sys.exit(main())
