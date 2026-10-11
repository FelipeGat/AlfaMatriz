#!/usr/bin/env python3
"""Backup cifrado de UM banco local e ensaio sem portas, rede externa ou IA."""
import hashlib
import json
import os
import secrets
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

PROJECT = Path(__file__).resolve().parents[2]
CENTRAL = PROJECT.parent / 'central-agentes'
DOCKER = ['docker', '--config', str(CENTRAL / '.docker')]
STAMP = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(3)
ART = CENTRAL / 'development/runtime/real-backup/artifacts' / ('canal-' + STAMP)
KEYS = CENTRAL / 'development/runtime/real-backup/keys'
NAME = 'central-canal-restore-' + STAMP.lower()
NET = NAME + '-net'
MIGRATION = 'database/migrations/2026_10_11_160000_canal_ativo_da_central_ia.php'


def call(args, payload=None, timeout=180):
    r = subprocess.run(DOCKER + args, input=payload, capture_output=True, timeout=timeout)
    if r.returncode:
        raise RuntimeError('Operação Docker falhou; código %d; conteúdo suprimido.' % r.returncode)
    return r.stdout


def query(container, sql, source=False):
    prefix = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" ' if source else ''
    return call(['exec', '-i', container, 'sh', '-c', prefix + 'mariadb -u root --batch --skip-column-names alfamatriz'], sql.encode())


def dump(container, source=False, data_only=False):
    prefix = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" ' if source else ''
    opts = '--single-transaction --skip-lock-tables --hex-blob --skip-comments --skip-extended-insert --complete-insert --order-by-primary '
    opts += '--no-create-info ' if data_only else '--routines --events --triggers '
    return call(['exec', '-i', container, 'sh', '-c', prefix + 'mariadb-dump -u root ' + opts + 'alfamatriz'])


def logical_hash(data):
    # INSERTs de um snapshot único; não compara novamente com o banco vivo.
    return hashlib.sha256(b'\n'.join(sorted(x for x in data.splitlines() if x.startswith(b'INSERT INTO ')))).hexdigest()


def counts(container):
    tables = query(container, "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name;").decode().splitlines()
    return {t: int(query(container, 'SELECT COUNT(*) FROM `' + t.replace('`', '``') + '`;')) for t in tables}


def main():
    os.umask(0o077)
    ART.mkdir(parents=True, mode=0o700)
    KEYS.mkdir(parents=True, exist_ok=True, mode=0o700)
    report = {'scope': 'alfamatriz-dev-db ONLY', 'inference': False, 'existing_services_restarted': False}
    created = network = False
    try:
        label = call(['inspect', '--format', '{{index .Config.Labels "com.docker.compose.project"}}', 'alfamatriz-dev-db']).decode().strip()
        if label != 'alfamatriz-dev': raise RuntimeError('Banco fora do escopo local autorizado.')
        if query('alfamatriz-dev-db', "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND engine <> 'InnoDB';", True).strip() != b'0':
            raise RuntimeError('Snapshot transacional exige InnoDB em todas as tabelas.')
        payload = dump('alfamatriz-dev-db', True)
        key, nonce = secrets.token_bytes(32), secrets.token_bytes(12)
        aad = ('central-canal-local/v1/' + STAMP).encode()
        encrypted = nonce + AESGCM(key).encrypt(nonce, payload, aad)
        backup = ART / 'alfamatriz.sql.aesgcm'
        backup.write_bytes(encrypted)
        (KEYS / ('canal-' + STAMP + '.key')).write_bytes(key)
        restored_payload = AESGCM(key).decrypt(encrypted[:12], encrypted[12:], aad)
        if restored_payload != payload: raise RuntimeError('Integridade AES-GCM divergente.')
        report.update({'cipher_sha256': hashlib.sha256(encrypted).hexdigest(), 'snapshot_logical_sha256': logical_hash(payload), 'aad': aad.decode()})
        call(['network', 'create', '--internal', NET]); network = True
        call(['run', '-d', '--pull=never', '--name', NAME, '--network', NET,
              '-e', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1', '-e', 'MARIADB_DATABASE=alfamatriz', 'mariadb:10.11']); created = True
        for _ in range(60):
            try:
                if call(['exec', NAME, 'cat', '/proc/1/comm']).strip() == b'mariadbd':
                    query(NAME, 'SELECT 1;'); break
            except RuntimeError: pass
            time.sleep(1)
        else: raise RuntimeError('MariaDB descartável não ficou pronto.')
        query(NAME, 'SET autocommit=0;\n' + restored_payload.decode() + '\nCOMMIT;')
        if logical_hash(dump(NAME, data_only=True)) != logical_hash(payload):
            raise RuntimeError('Restauração lógica divergente; ativação bloqueada.')
        before = counts(NAME)
        blank = ART / 'empty-env'; blank.write_bytes(b'')
        vendor = PROJECT / 'vendor'
        if not (vendor / 'autoload.php').is_file(): vendor = CENTRAL / 'development/runtime/alfamatriz-vendor'
        cmd = ['run', '--rm', '--pull=never', '--network', NET, '--read-only', '--user', '1000:1000',
               '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true', '--workdir', '/var/www']
        for src, dst in [(PROJECT, '/var/www'), (vendor, '/var/www/vendor'), (blank, '/var/www/.env'), (blank, '/var/www/docker/local/telegram.env')]:
            cmd += ['--mount', 'type=bind,src=' + str(src) + ',dst=' + dst + ',readonly']
        for target in ['/tmp', '/var/www/storage', '/var/www/bootstrap/cache']:
            cmd += ['--tmpfs', target + ':rw,nosuid,uid=1000,gid=1000,size=64m']
        for v in ['APP_ENV=testing', 'APP_DEBUG=false', 'APP_KEY=base64:MTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTE=',
                  'DB_CONNECTION=mysql', 'DB_HOST=' + NAME, 'DB_PORT=3306', 'DB_DATABASE=alfamatriz', 'DB_USERNAME=root', 'DB_PASSWORD=', 'CACHE_STORE=array', 'SESSION_DRIVER=array']:
            cmd += ['-e', v]
        cmd += ['alfamatriz-dev-app', 'php', 'artisan', 'migrate', '--force', '--path=' + MIGRATION]
        call(cmd)
        after = counts(NAME)
        changed = {t for t in before if before[t] != after[t]}
        if changed - {'migrations'}: raise RuntimeError('Quantidade de registros mudou no ensaio.')
        schema = query(NAME, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='central_ia_conversas' AND column_name IN ('canal_ativo','projeto_canal','tarefa_canal','canal_desde_id');").strip()
        if schema != b'4': raise RuntimeError('Schema do canal ativo não confirmado.')
        if query(NAME, "SELECT COUNT(*) FROM central_ia_conversas WHERE canal_ativo <> 'web';").strip() != b'0': raise RuntimeError('Estado inicial diferente de web.')
        report.update({'restore_integrity': True, 'migration_rehearsal': True, 'tables_verified': len(before), 'row_counts_preserved': True, 'default_channel_web': True})
        print(json.dumps(report, ensure_ascii=False))
    finally:
        if created: call(['rm', '-f', '-v', NAME])
        if network: call(['network', 'rm', NET])
        (ART / 'report.json').write_text(json.dumps(report, indent=2))


if __name__ == '__main__':
    try: main()
    except Exception as e:
        print('Ensaio interrompido: ' + type(e).__name__ + '; detalhes privados suprimidos.')
        raise SystemExit(1)
