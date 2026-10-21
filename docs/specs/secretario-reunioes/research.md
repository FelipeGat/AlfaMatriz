# Research: Secretário, Etapa 4 (Matriz Meetings)

## Decision 1: Transcrição na máquina, num container

**Decision**: imagem `alfamatriz-central-whisper:local` (Python + faster-whisper + modelo `small` baixado no build).
O runner a roda sem rede (`--network=none`), como já faz com as imagens de mídia e de documentos
(`deploy/central-ia/anexos.mjs`).

O script `transcrever_reuniao.py` deriva do `deploy/agente/transcrever.py`, que já roda em produção no Mac mini:
mesmo decodificador PyAV, VAD e `temperature=0`. Para reunião, muda o seguinte:
- os trechos saem com marcação de tempo (`without_timestamps=False`);
- o teto de tokens por janela fica no padrão, porque a reunião tem frases longas.

**Rationale**:
- Decisão do Felipe de áudio local (auditoria).
- A WSL não tem pip nem venv (memória `wsl-python-sem-pip-e-venv`), então o container resolve a dependência.
- O modelo dentro da imagem evita rede na hora de transcrever.

**Alternatives considered**: serviço externo (Whisper API, Deepgram), descartado por privacidade e custo, conforme a
decisão do dono; Whisper no servidor, descartado porque o servidor não tem GPU nem folga e a decisão é manter o
áudio na máquina.

## Decision 2: Fila própria de transcrição no servidor

Rotas novas do runner, com o mesmo token `central-ia-runner`:
- `transcricoes/proxima`, que reserva com trava (`lockForUpdate`), como o `proxima` das execuções;
- `transcricoes/{id}/audio`;
- `transcricoes/{id}` para o resultado.

A transcrição não é uma "execução de agente": não tem prompt, cota nem roteador. Misturar com
`central_ia_execucoes` exigiria mudar o modelo de execução (modos, roteamento, painel).

## Decision 3: Análise como execução da Central

A análise é um pedido (`AgentesService::pedir`, projeto `''`, modo `analisar`). O texto curto visível na conversa
é "Analisar a reunião #N (Secretário)", e a instrução leva a transcrição numerada, com a ordem de responder só
com o bloco `<<<REUNIAO {json} REUNIAO>>>`.

Na conclusão (`AgentesService::concluir`), o mesmo gancho já usado pelo `PropostaDaIa` extrai, valida e grava o
bloco, e põe na conversa uma linha com o link no lugar do JSON.

A instrução vai cortada em 60.000 bytes (a coluna `instrucao` é TEXT). A transcrição passa de ~40 mil caracteres?
Vai cortada, e a análise é marcada como parcial.

## Decision 4: Validação da análise

- `tipo` dentro da lista;
- `resumo` com até 4.000 caracteres;
- `contexto` com 6 campos de texto curto;
- listas com até 50 itens;
- cada decisão, tarefa ou pendência com `evidencias` entre 1 e 10 números de trecho existentes;
- `classificacao` dentro da lista;
- `prazo` no formato `AAAA-MM-DD` (ou `AAAA-MM-DD HH:MM`) ou nulo.

Qualquer falha descarta a análise inteira (FR-006): "análise falhou", com o botão "Analisar de novo".

## Decision 5: Ditado

Voz do Telegram (`message.voice`) com `duration` ≤ 60 s vira reunião no modo `ditado`. Na volta da transcrição, o
texto vai ao `Conversa::doTelegram` (Etapa 1), como se tivesse sido digitado. A Central só recebe pela regra atual
quando não é do Secretário. Áudio (`message.audio`), documento de áudio ou voz acima de 60 s vira `reuniao`.

## Decision 6: Áudio apagado

O arquivo é apagado na entrega do resultado (sucesso ou falha) e também quando a reunião estoura as 3 tentativas.
Ficam o sha256 e a duração.

## Decision 7: Gravação no navegador

`MediaRecorder` com `audio/webm;codecs=opus` (ou `audio/ogg` quando o navegador só oferece esse), a 24 kbps. Isso
dá cerca de 1 h em 12 MB. O envio é pelo mesmo upload.
