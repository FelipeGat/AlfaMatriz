#!/usr/bin/env python3
"""
Transcreve o áudio de uma reunião em português, com marcação de tempo por
trecho, na máquina da própria pessoa (docs/specs/secretario-reunioes).

Deriva de deploy/agente/transcrever.py (o mesmo decodificador PyAV, VAD e
temperatura 0, que já resolveram as alucinações em silêncio). O que muda para
reunião: os trechos saem com início e fim, e o teto de tokens por janela fica
no padrão — numa reunião as frases são longas.

Progresso (stderr, uma linha por segmento): {"progresso": s, "duracao": s}
Saída (stdout): {"duracao": s, "trechos": [{"inicio": s, "fim": s, "texto": "…"}]}
ou {"erro": "…"} com código 1.

Vídeo do WhatsApp para o Pedro (--video N, Felipe, 20/10/2026): N quadros em
JPEG, espalhados pelo vídeo e já na posição em que o celular gravou, mais a
fala, se houver. Saída: {"duracao": s, "quadros": ["<base64>", …], "trechos": […]};
vídeo sem fala não é erro.
"""

import base64
import json
import os
import sys
from fractions import Fraction

import av
import numpy as np
from faster_whisper import WhisperModel

VOCABULARIO = os.environ.get(
    "WHISPER_VOCABULARIO",
    "AlfaMatriz, AlfaGym, AlfaControl, AlfaHome, AlfaJornada, RFID, NFC, "
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


LADO_MAXIMO = 768


def jpeg(quadro) -> str:
    """O quadro em JPEG (base64), girado como o celular gravou e com o maior lado em LADO_MAXIMO."""
    rgb = quadro.to_ndarray(format="rgb24")
    # Celular grava deitado e marca a rotação no arquivo; sem girar, a foto sai de lado.
    giro = int(round(float(getattr(quadro, "rotation", 0) or 0) / 90)) % 4
    if giro:
        rgb = np.ascontiguousarray(np.rot90(rgb, k=giro))
    altura, largura = rgb.shape[:2]
    escala = min(1.0, LADO_MAXIMO / max(altura, largura))
    largura, altura = max(2, int(largura * escala) // 2 * 2), max(2, int(altura * escala) // 2 * 2)
    final = av.VideoFrame.from_ndarray(rgb, format="rgb24").reformat(width=largura, height=altura, format="yuvj420p")
    codec = av.CodecContext.create("mjpeg", "w")
    codec.width, codec.height, codec.pix_fmt, codec.time_base = largura, altura, "yuvj420p", Fraction(1, 25)
    return base64.b64encode(b"".join(bytes(p) for p in codec.encode(final) + codec.encode(None))).decode()


def quadros(caminho: str, n: int) -> tuple[list[str], float]:
    """N quadros espalhados pelo vídeo (no meio de cada fatia), e a duração."""
    with av.open(caminho) as arquivo:
        if not arquivo.streams.video:
            return [], 0.0
        fluxo = arquivo.streams.video[0]
        duracao = float(arquivo.duration / 1_000_000) if arquivo.duration else (float(fluxo.duration * fluxo.time_base) if fluxo.duration else 0.0)
        alvos = [duracao * (i + 0.5) / n for i in range(n)] if duracao > 0 else [0.0]
        escolhidos, ultimo = [], None
        for quadro in arquivo.decode(video=0):
            ultimo = quadro
            if len(escolhidos) < len(alvos) and (quadro.time or 0.0) >= alvos[len(escolhidos)]:
                escolhidos.append(jpeg(quadro))
                if len(escolhidos) == len(alvos):
                    break
        if not escolhidos and ultimo is not None:
            escolhidos.append(jpeg(ultimo))
        return escolhidos, duracao


def video(caminho: str, n: int) -> int:
    imagens, duracao = quadros(caminho, n)
    trechos = []
    try:
        audio = decodificar(caminho)
    except Exception:  # noqa: BLE001 — vídeo sem trilha de som
        audio = np.zeros(0, dtype=np.float32)
    if audio.size:
        modelo = WhisperModel("small", device="cpu", compute_type="int8",
                              download_root=os.environ.get("WHISPER_MODELOS", "/modelos"))
        segmentos, _ = modelo.transcribe(audio, language="pt", beam_size=2, vad_filter=True, hotwords=VOCABULARIO,
                                         temperature=0.0, condition_on_previous_text=False)
        trechos = [{"inicio": round(s.start, 2), "fim": round(s.end, 2), "texto": s.text.strip()} for s in segmentos if s.text.strip()]
    if not imagens and not trechos:
        print(json.dumps({"erro": "vídeo ilegível"}))
        return 1
    print(json.dumps({"duracao": round(duracao, 1), "quadros": imagens, "trechos": trechos}, ensure_ascii=False))
    return 0


def main() -> int:
    if len(sys.argv) == 4 and sys.argv[1] == "--video":
        try:
            return video(sys.argv[3], max(1, min(6, int(sys.argv[2]))))
        except Exception as e:  # noqa: BLE001
            print(json.dumps({"erro": str(e)[:200]}))
            return 1
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
        # Os segmentos saem um a um enquanto o modelo trabalha: cada um avisa no
        # stderr até onde o áudio já foi, e o runner transforma isso na barra.
        trechos = []
        for s in segmentos:
            print(json.dumps({"progresso": round(s.end, 1), "duracao": round(float(info.duration), 1)}), file=sys.stderr, flush=True)
            if s.text.strip():
                trechos.append({"inicio": round(s.start, 2), "fim": round(s.end, 2), "texto": s.text.strip()})
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
