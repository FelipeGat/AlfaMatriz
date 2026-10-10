#!/usr/bin/env python3
"""Reproduce the isolated AlfaMatriz checkout test run; no real database."""
import argparse
import shutil
import subprocess
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--central-root', type=Path, required=True)
args = parser.parse_args()
central = args.central_root.resolve()
vendor = central / 'development/runtime/alfamatriz-vendor'
node = shutil.which('node')
if not (vendor / 'autoload.php').is_file() or not node:
    raise SystemExit('Vendor do laboratório e Node existentes são necessários; nenhuma instalação realizada.')
gitdir = subprocess.check_output(['git', '-C', str(root), 'rev-parse', '--path-format=absolute', '--git-common-dir'], text=True).strip()
for name in ['vendor', 'storage', 'bootstrap/cache']:
    (root / name).mkdir(parents=True, exist_ok=True)
# O checkout também serve a porta 8096 e tem `.env` de verdade (APP_URL,
# raiz de anexos em /var/www). Sem esconder, ele vaza para os testes e
# derruba ~60 deles por motivo de ambiente, não de código.
empty_env = Path(tempfile.mkdtemp()) / '.env'
empty_env.write_text('')
command = ['docker', '--config', str(central / '.docker'), 'run', '--rm', '--pull=never',
           '--network', 'none', '--user', '1000:1000', '--read-only', '--cap-drop', 'ALL',
           '--security-opt', 'no-new-privileges:true', '--workdir', '/var/www/html']
for source, target in [(root, '/var/www/html'), (vendor, '/var/www/html/vendor'), (empty_env, '/var/www/html/.env'),
                       (root / 'tools/central-ia/testing/TestCase.php', '/var/www/html/tests/TestCase.php'),
                       (Path(gitdir), gitdir), (Path(node), '/usr/local/bin/node')]:
    command += ['--mount', 'type=bind,src=' + str(source) + ',dst=' + target + ',readonly']
for mount in ['/tmp:rw,exec,nosuid,uid=1000,gid=1000,size=128m',
              '/var/www/html/storage:rw,noexec,nosuid,uid=1000,gid=1000,size=64m',
              '/var/www/html/bootstrap/cache:rw,noexec,nosuid,uid=1000,gid=1000,size=8m']:
    command += ['--tmpfs', mount]
# Deliberately fake, public test key; no existing APP_KEY or .env is loaded.
for value in ['APP_ENV=testing', 'APP_DEBUG=false', 'APP_LOCALE=pt_BR',
              'APP_KEY=base64:MTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTE=',
              'DB_CONNECTION=sqlite', 'DB_DATABASE=:memory:']:
    command += ['-e', value]
command += ['central-alfamatriz-php82:development', 'sh', '-c',
            'mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; '
            'exec php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result']
raise SystemExit(subprocess.run(command).returncode)
