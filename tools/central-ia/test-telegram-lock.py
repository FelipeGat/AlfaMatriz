#!/usr/bin/env python3
"""Named-lock MariaDB regression in a disposable container, without real data."""
import argparse
import json
import subprocess
import time
import uuid

parser = argparse.ArgumentParser()
parser.add_argument('--docker-config', required=True)
parser.add_argument('--hold-seconds', type=int, default=62)
args = parser.parse_args()
docker = ['docker', '--config', args.docker_config]
name = 'central-ia-lock-test-' + uuid.uuid4().hex[:12]
created = False


def sql(query):
    result = subprocess.run(docker + ['exec', name, 'mariadb', '--no-defaults',
        '--user=root', '--batch', '--skip-column-names', '-e', query],
        capture_output=True, text=True, timeout=10)
    if result.returncode:
        raise RuntimeError('SQL de teste não concluiu; saída omitida.')
    return result.stdout.strip()


try:
    result = subprocess.run(docker + ['run', '--detach', '--rm', '--pull=never', '--name', name,
        '--network', 'none', '--read-only', '--user', '999:999', '--cap-drop', 'ALL',
        '--security-opt', 'no-new-privileges:true', '--memory', '512m', '--cpus', '1',
        '--tmpfs', '/var/lib/mysql:rw,nosuid,uid=999,gid=999,size=256m',
        '--tmpfs', '/run/mysqld:rw,nosuid,uid=999,gid=999,size=16m',
        '--tmpfs', '/tmp:rw,nosuid,uid=999,gid=999,size=64m',
        '-e', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1', 'mariadb:10.11'],
        capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Contêiner de teste não iniciou; saída omitida.')
    created = True
    for attempt in range(60):
        try:
            pid1 = subprocess.run(docker + ['exec', name, 'cat', '/proc/1/comm'], capture_output=True, text=True, timeout=10)
            if pid1.stdout.strip() == 'mariadbd' and sql('SELECT 1') == '1':
                break
        except RuntimeError:
            time.sleep(0.5)
    else:
        raise RuntimeError('MariaDB descartável não ficou pronto.')

    lock = 'central-ia:telegram:teste'
    # O dono roda dentro do contêiner, desacoplado do stream docker exec.
    # Aguardar PID 1 mariadbd evita o servidor temporário da inicialização,
    # que aceita SQL mas será encerrado antes do servidor definitivo.
    subprocess.run(docker + ['exec', '--detach', name, 'sh', '-c',
        "exec mariadb --no-defaults --user=root --batch --skip-column-names "
        "-e \"SELECT GET_LOCK('central-ia:telegram:teste', 0); SELECT SLEEP(600)\" >/tmp/holder.out 2>/tmp/holder.err"],
        capture_output=True, timeout=10, check=True)
    for attempt in range(30):
        owner = sql(f"SELECT IS_USED_LOCK('{lock}')")
        if owner.isdigit():
            connection_id = int(owner)
            break
        time.sleep(0.1)
    else:
        raise RuntimeError('Dono descartável não adquiriu a trava.')
    assert sql(f"SELECT GET_LOCK('{lock}', 0)") == '0'
    started = time.monotonic()
    print(f'Segundo consumidor recusado. Verificando retenção por {args.hold_seconds} s...', flush=True)
    time.sleep(args.hold_seconds)
    result = sql(f"SELECT GET_LOCK('{lock}', 0)")
    if result != '0':
        err = subprocess.run(docker + ['exec', name, 'cat', '/tmp/holder.err'], capture_output=True, text=True, timeout=10)
        print('Erro do dono SQL descartável: ' + err.stdout[:500], flush=True)
        raise RuntimeError('Conexão dona não permaneceu ativa')
    # Derruba somente a conexão deste banco descartável, simulando queda do dono.
    sql(f'KILL {connection_id}')
    assert sql(f"SELECT GET_LOCK('{lock}', 0); SELECT RELEASE_LOCK('{lock}')") == '1\n1'
    print(json.dumps({'segundo_consumidor_bloqueado': True, 'retencao_segundos': args.hold_seconds, 'elapsed_seconds': round(time.monotonic() - started, 1),
        'recuperacao_apos_queda': True, 'dados_reais': False, 'rede': 'none'}), flush=True)
finally:
    if created:
        subprocess.run(docker + ['rm', '--force', name], capture_output=True, timeout=15, check=True)
