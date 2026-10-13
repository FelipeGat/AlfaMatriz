#!/usr/bin/env python3
"""Aplicar somente a migração aditiva de preferências no AlfaMatriz dev autorizado."""
import argparse
import json
import subprocess
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--apply', action='store_true', required=True)
parser.add_argument('--report', type=Path, required=True, help='Relatório do backup/restauração e ensaio aprovados')
args = parser.parse_args()
report = json.loads(args.report.read_text())
if not (report.get('restore_integrity') and report.get('migration_rehearsal') and report.get('row_counts_preserved')
        and report.get('migration') == 'preferencias' and report.get('scope') == 'alfamatriz-dev-db ONLY'):
    raise SystemExit('Ensaio de preferências não comprovado; ativação bloqueada.')
root = Path(__file__).resolve().parents[2]
docker = ['docker', '--config', str(root.parent / 'central-agentes/.docker')]


def run(args, data=None):
    r = subprocess.run(docker + args, input=data, capture_output=True, timeout=180)
    if r.returncode:
        raise RuntimeError('Operação local falhou; saída privada suprimida, código %d.' % r.returncode)
    return r.stdout


def counts():
    tables = ['central_ia_conversas', 'central_ia_mensagens', 'central_ia_tarefas',
              'central_ia_execucoes', 'central_ia_entregas', 'central_ia_telegram_vinculos']
    sql = ' UNION ALL '.join("SELECT '%s', COUNT(*) FROM `%s`" % (t, t) for t in tables) + ';'
    result = run(['exec', '-i', 'alfamatriz-dev-db', 'sh', '-c',
                  'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -u root --batch --skip-column-names alfamatriz'], sql.encode())
    return {line.split()[0]: int(line.split()[1]) for line in result.decode().splitlines()}


for name in ['alfamatriz-dev-app', 'alfamatriz-dev-db']:
    label = run(['inspect', '--format', '{{index .Config.Labels "com.docker.compose.project"}}', name]).strip()
    if label != b'alfamatriz-dev':
        raise SystemExit('Contêiner fora do ambiente de desenvolvimento; interrompido.')
before = counts()
run(['exec', '--workdir', '/var/www', 'alfamatriz-dev-app', 'php', 'artisan', 'migrate', '--force',
     '--path=database/migrations/2026_10_11_180000_preferencias_individuais_central_ia.php'])
after = counts()
print(json.dumps({'scope': 'alfamatriz-dev ONLY', 'migration': 'preferencias', 'protected_counts_before': before,
                  'protected_counts_after': after, 'counts_equal': before == after,
                  'services_restarted': False, 'inference': False}))
