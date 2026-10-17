# Transcrição de reuniões do Secretário (docs/specs/secretario-reunioes): roda
# na máquina da pessoa, sem rede. O modelo é baixado AQUI, no build, para a
# transcrição nunca precisar sair da máquina. Contexto: deploy/central-ia
#   docker build -t alfamatriz-central-whisper:local -f deploy/central-ia/whisper/Dockerfile deploy/central-ia
FROM python:3.11-slim
RUN pip install --no-cache-dir "faster-whisper==1.1.1" requests \
 && python -c "from faster_whisper import WhisperModel; WhisperModel('small', device='cpu', compute_type='int8', download_root='/modelos')" \
 && chmod -R a+rX /modelos
ENV HF_HUB_OFFLINE=1 WHISPER_MODELOS=/modelos
COPY transcrever_reuniao.py /app/transcrever_reuniao.py
