#!/usr/bin/env python3
"""
Transcreve o áudio de uma reunião em português, com marcação de tempo por
trecho, na máquina da própria pessoa (docs/specs/secretario-reunioes).

Deriva de deploy/agente/transcrever.py (o mesmo decodificador PyAV, VAD e
temperatura 0, que já resolveram as alucinações em silêncio). O que muda para
reunião: os trechos saem com início e fim, e o teto de tokens por janela fica
no padrão — numa reunião as frases são longas.

Saída (stdout): {"duracao": s, "trechos": [{"inicio": s, "fim": s, "texto": "…"}]}
ou {"erro": "…"} com código 1.
"""

import json
import os
import sys

import av
import numpy as np
from faster_whisper import WhisperModel

VOCABULARIO = os.environ.get(
    "WHISPER_VOCABULARIO",
    "AlfaMatriz, AlfaGym, AlfaControl, AlfaHome, VixCard, VixHouse, Vix-ID, VixLink, VixTrace, RFID, NFC, "
    "Grupo Soluções, Delta, Invest, GW, Gestor, proposta, orçamento, Condonal.",
)


def decodificar(caminho: str) -> np.ndarray:
    reamostrador = av.AudioResampler(format="s16", layout="mono", rate=16000)
    pedacos = []
    with av.open(caminho) as arquivo:
        for quadro in arquivo.decode(audio=0):
            pedacos.extend(r.to_ndarray().reshape(-1) for r in reamostrador.resample(quadro))
        pedacos.extend(r.to_ndarray().reshape(-1) for r in reamostrador.resample(None))
    if not pedacos:
        return np.zeros(0, dtype=np.float32)
    return np.concatenate(pedacos).astype(np.float32) / 32768.0


def main() -> int:
    if len(sys.argv) != 2:
        print(json.dumps({"erro": "uso: transcrever_reuniao.py <arquivo>"}))
        return 2
    try:
        audio = decodificar(sys.argv[1])
        if audio.size == 0:
            print(json.dumps({"erro": "áudio vazio ou ilegível"}))
            return 1
        modelo = WhisperModel("small", device="cpu", compute_type="int8",
                              download_root=os.environ.get("WHISPER_MODELOS", "/modelos"))
        segmentos, info = modelo.transcribe(
            audio, language="pt", beam_size=2, vad_filter=True, hotwords=VOCABULARIO,
            temperature=0.0, condition_on_previous_text=False,
        )
        trechos = [{"inicio": round(s.start, 2), "fim": round(s.end, 2), "texto": s.text.strip()}
                   for s in segmentos if s.text.strip()]
        if not trechos:
            print(json.dumps({"erro": "não encontrei fala no áudio"}))
            return 1
        print(json.dumps({"duracao": round(float(info.duration), 1), "trechos": trechos}, ensure_ascii=False))
        return 0
    except Exception as e:  # noqa: BLE001 — o runner precisa do motivo, não de um traceback
        print(json.dumps({"erro": str(e)[:200]}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
