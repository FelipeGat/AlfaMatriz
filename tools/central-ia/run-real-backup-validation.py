#!/usr/bin/env python3
"""Explicitly authorized local backup drill. Never prints dumps or credentials."""
import hashlib
import json
import os
import secrets
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.exceptions import InvalidTag

ROOT = Path(os.environ.get('CENTRAL_BACKUP_ROOT', Path(__file__).resolve().parent.parent)).resolve()
if not (ROOT / 'development/identity-files.json').is_file():
    raise SystemExit('Defina CENTRAL_BACKUP_ROOT para a Central local original; nenhuma operação realizada.')
RUNTIME = ROOT / 'development/runtime/real-backup'
DOCKER = ['docker', '--config', str(ROOT / '.docker')]

def call(args, payload=None):
    result = subprocess.run(DOCKER + args, input=payload, capture_output=True, timeout=180)
    if result.returncode:
        raise RuntimeError('Etapa de backup/restauração falhou; código %d. Conteúdo privado suprimido.' % result.returncode)
    return result.stdout

def execute(container, command, payload=None):
    return call(['exec', '-i', container, 'sh', '-c', command], payload)

def query(container, engine, sql):
    if engine == 'maria':
        command = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -u root --batch --skip-column-names alfamatriz'
    else:
        command = 'PGPASSWORD="$POSTGRES_PASSWORD" psql -X -v ON_ERROR_STOP=1 -At -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
    return execute(container, command, sql.encode()).decode().strip()

def manifest(container, engine):
    if engine == 'maria':
        tables = query(container, engine, "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name;").splitlines()
        queries = ['SELECT COUNT(*) FROM `' + t.replace('`', '``') + '`;' for t in tables]
    else:
        tables = query(container, engine, "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename;").splitlines()
        queries = ['SELECT COUNT(*) FROM public."' + t.replace('"', '""') + '";' for t in tables]
    counts = query(container, engine, '\n'.join(queries)).splitlines()
    return dict(zip(tables, map(int, counts)))

def dump(container, engine, data_only=False):
    if engine == 'maria':
        command = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb-dump -u root --single-transaction --skip-lock-tables --hex-blob '
        command += '--skip-comments --compact --no-create-info --skip-extended-insert --order-by-primary ' if data_only else '--routines --events --triggers '
        return execute(container, command + 'alfamatriz')
    command = 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-acl '
    if not data_only:
        return execute(container, command + '--format=custom')
    data = execute(container, command + '--data-only')
    return b'\n'.join(line for line in data.splitlines() if not line.startswith((b'\\restrict ', b'\\unrestrict ')))

def main():
    os.umask(0o077)
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(3)
    destination = RUNTIME / 'artifacts' / stamp
    keys = RUNTIME / 'keys'
    destination.mkdir(parents=True, mode=0o700)
    keys.mkdir(parents=True, exist_ok=True, mode=0o700)
    key = secrets.token_bytes(32)
    keyfile = keys / (stamp + '.key')
    keyfile.write_bytes(key)
    network = 'central-real-restore-' + stamp.lower()
    names = []
    report = {'started_at_utc': stamp, 'inference_executed': False, 'existing_services_restarted': False,
              'real_link_or_migration_performed': False, 'plaintext_files_created': False, 'databases': {}}
    sources = [('alfamatriz', 'alfamatriz-dev-db', 'maria', 'mariadb:10.11'),
               ('central', 'central-agentes-db-1', 'postgres', 'postgres:17')]
    try:
        # Inspect selected metadata only; never serialize Config.Env.
        for label, source, engine, image in sources:
            labels = json.loads(call(['inspect', '--format', '{{json .Config.Labels}}', source]))
            expected = 'alfamatriz-dev' if engine == 'maria' else 'central-agentes'
            if labels.get('com.docker.compose.project') != expected:
                raise RuntimeError('Origem não corresponde ao projeto local autorizado.')
            if engine == 'maria' and query(source, engine, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND engine <> 'InnoDB';") != '0':
                raise RuntimeError('Snapshot transacional não comprovado para todas as tabelas; backup interrompido.')
        call(['network', 'create', '--internal', network])
        for label, source, engine, image in sources:
            report['phase'] = label + ':snapshot'
            before = manifest(source, engine)
            source_data_hash = hashlib.sha256(dump(source, engine, True)).hexdigest()
            payload = dump(source, engine)
            nonce = secrets.token_bytes(12)
            aad = ('central-local-backup/v1/' + label).encode()
            encrypted = nonce + AESGCM(key).encrypt(nonce, payload, aad)
            artifact = destination / (label + '.aesgcm')
            artifact.write_bytes(encrypted)
            restored_payload = AESGCM(key).decrypt(encrypted[:12], encrypted[12:], aad)
            if hashlib.sha256(restored_payload).digest() != hashlib.sha256(payload).digest():
                raise RuntimeError('Integridade do dump não comprovada.')
            for candidate_key, ciphertext in [(secrets.token_bytes(32), encrypted), (key, encrypted[:-1] + bytes([encrypted[-1] ^ 1]))]:
                try:
                    AESGCM(candidate_key).decrypt(ciphertext[:12], ciphertext[12:], aad)
                except InvalidTag:
                    continue
                raise RuntimeError('Cifragem aceitou chave incorreta ou adulteração.')
            name = network + '-' + label
            report['phase'] = label + ':isolated-start'
            environment = destination / (label + '.restore-env')
            password = secrets.token_hex(32)
            environment.write_text(('MARIADB_DATABASE=alfamatriz\nMARIADB_ROOT_PASSWORD=' + password + '\n') if engine == 'maria'
                                   else ('POSTGRES_DB=central_restore\nPOSTGRES_USER=postgres\nPOSTGRES_PASSWORD=' + password + '\n'))
            args = ['run', '-d', '--pull=never', '--name', name, '--network', network, '--user', '999:999', '--read-only',
                    '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--env-file', str(environment),
                    '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,uid=999,gid=999,size=64m']
            if engine == 'maria':
                args += ['--tmpfs', '/var/lib/mysql:rw,nosuid,nodev,uid=999,gid=999,size=512m',
                         '--tmpfs', '/run/mysqld:rw,nosuid,nodev,uid=999,gid=999,size=8m']
            else:
                args += ['--tmpfs', '/var/lib/postgresql/data:rw,nosuid,nodev,uid=999,gid=999,size=512m',
                         '--tmpfs', '/var/run/postgresql:rw,nosuid,nodev,uid=999,gid=999,size=8m']
            call(args + [image])
            names.append(name)
            environment.unlink()  # Own throwaway DB password only; no existing secret files touched.
            isolated = json.loads(call(['inspect', '--format', '{"ports":{{json .HostConfig.PortBindings}},"mounts":{{json .Mounts}},"readonly":{{json .HostConfig.ReadonlyRootfs}}}', name]))
            if isolated['ports'] or any(m['Type'] != 'tmpfs' for m in isolated['mounts']) or not isolated['readonly']:
                raise RuntimeError('Restrições do banco de restauração não comprovadas.')
            deadline = time.monotonic() + 90
            while True:
                try:
                    query(name, engine, 'SELECT 1;')
                    break
                except RuntimeError:
                    if time.monotonic() >= deadline:
                        raise RuntimeError('Inicialização do banco isolado excedeu o prazo; conteúdo dos logs suprimido.')
                    time.sleep(1)
            if engine == 'maria':
                report['phase'] = label + ':restore'
                execute(name, 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -u root alfamatriz', restored_payload)
                if query(name, engine, 'SELECT @@event_scheduler;') != 'OFF':
                    raise RuntimeError('Event scheduler de restauração não está bloqueado.')
            else:
                report['phase'] = label + ':restore'
                execute(name, 'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --exit-on-error --no-owner --no-acl', restored_payload)
            restored = manifest(name, engine)
            report['phase'] = label + ':integrity'
            restored_data_hash = hashlib.sha256(dump(name, engine, True)).hexdigest()
            after = manifest(source, engine)
            if before != restored or source_data_hash != restored_data_hash:
                raise RuntimeError('Conteúdo mudou durante o ensaio ou restauração divergiu; não autorizar ativação.')
            report['databases'][label] = {'tables': len(restored), 'records': sum(restored.values()),
                 'restored_counts_match': True, 'restored_data_digest_matches': True, 'live_counts_unchanged_during_drill': before == after,
                 'encrypted_artifact_sha256': hashlib.sha256(encrypted).hexdigest(), 'wrong_key_rejected': True,
                 'tampered_artifact_rejected': True, 'no_host_mounts_or_published_ports': True}
            del payload, restored_payload
        report['passed'] = True
        report['phase'] = 'completed'
    except (RuntimeError, subprocess.TimeoutExpired, ValueError):
        report['passed'] = False
        report['error'] = 'Ensaio interrompido; verifique a etapa isolada sem exibir dados ou credenciais. Nenhuma ativação realizada.'
    finally:
        cleanup = True
        for name in names:
            result = subprocess.run(DOCKER + ['rm', '-f', name], capture_output=True, timeout=30)
            cleanup = cleanup and result.returncode == 0
        result = subprocess.run(DOCKER + ['network', 'rm', network], capture_output=True, timeout=30)
        cleanup = cleanup and result.returncode == 0
        report['temporary_restore_resources_removed'] = cleanup
        reportfile = destination / 'report.json'
        reportfile.write_text(json.dumps(report, indent=2) + '\n')
        (ROOT / 'development/reports/real-backup-validation.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report))
    return 0 if report.get('passed') and cleanup else 1

if __name__ == '__main__':
    raise SystemExit(main())
