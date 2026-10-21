# -*- coding: utf-8 -*-
"""Agenda do Google para o Secretário do AlfaMatriz, lida pela máquina da pessoa.

Fica na MESMA pasta da ponte pessoal do Google Calendar (a que já tem o token
autorizado) e usa o `servico()` dela. SÓ LEITURA: calendarList e events.list,
nunca insert/update/delete. Imprime uma linha só, marcada:

    SECRETARIO_AGENDA:{"agendas": [...], "eventos": [...]}

O runner manda isso ao AlfaMatriz (docs/specs/secretario-agenda-pela-maquina).
"""
import datetime as dt
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding="utf-8")

from gcal import servico  # noqa: E402  (a ponte pessoal, com o token já autorizado)

DIAS_ATRAS = 2
DIAS_FRENTE = 35
POR_AGENDA = 500


def main():
    svc = servico()
    agendas, token = [], None
    while True:
        r = svc.calendarList().list(pageToken=token, maxResults=250).execute()
        agendas += r.get("items", [])
        token = r.get("nextPageToken")
        if not token or len(agendas) >= 100:
            break

    agora = dt.datetime.now(dt.timezone.utc)
    de = (agora - dt.timedelta(days=DIAS_ATRAS)).isoformat()
    ate = (agora + dt.timedelta(days=DIAS_FRENTE)).isoformat()
    saida_agendas, saida_eventos = [], []
    for c in agendas:
        saida_agendas.append({"id": c["id"], "nome": c.get("summaryOverride") or c.get("summary") or c["id"], "primaria": bool(c.get("primary"))})
        lidos, pagina = 0, None
        while True:
            r = svc.events().list(calendarId=c["id"], timeMin=de, timeMax=ate, singleEvents=True, orderBy="startTime",
                                  maxResults=250, pageToken=pagina).execute()
            for e in r.get("items", []):
                if e.get("status") == "cancelled" or "id" not in e:
                    continue
                ini, fim = e.get("start", {}), e.get("end", {})
                dia_inteiro = "date" in ini
                saida_eventos.append({
                    "agenda": c["id"], "id": e["id"], "titulo": (e.get("summary") or "").strip()[:255],
                    "inicio": ini.get("date") if dia_inteiro else ini.get("dateTime"),
                    "fim": fim.get("date") if dia_inteiro else fim.get("dateTime"),
                    "dia_inteiro": dia_inteiro, "link": e.get("htmlLink"),
                })
                lidos += 1
            pagina = r.get("nextPageToken")
            if not pagina or lidos >= POR_AGENDA:
                break

    print("SECRETARIO_AGENDA:" + json.dumps({"agendas": saida_agendas, "eventos": saida_eventos}, ensure_ascii=False))


if __name__ == "__main__":
    main()
