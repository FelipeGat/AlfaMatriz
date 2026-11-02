#!/usr/bin/env python3
"""Uso: rodar-php.py <fase> <pasta> [<áudio transcrito>] [<arquivo de teste>]. Roda a bateria do orçamento no mesmo container isolado do run-tests.py, com /bateria montada (rw)."""
import shutil, subprocess, sys, tempfile
from pathlib import Path
root = Path(__file__).resolve().parents[4]
central = Path(__import__('os').environ.get('CENTRAL_ROOT', str(root.parent / 'central-agentes')))
vendor = central / 'development/runtime/alfamatriz-vendor'
fase, pasta = sys.argv[1], sys.argv[2]
arquivo = sys.argv[4] if len(sys.argv) > 4 else 'tools/central-ia/bateria-pedro/OrcamentoCenariosTest.php'
audio = sys.argv[3] if len(sys.argv) > 3 else ''
node = shutil.which('node')
gitdir = subprocess.check_output(['git', '-C', str(root), 'rev-parse', '--path-format=absolute', '--git-common-dir'], text=True).strip()
empty_env = Path(tempfile.mkdtemp()) / '.env'; empty_env.write_text('')
cmd = ['docker', '--config', str(central / '.docker'), 'run', '--rm', '--pull=never', '--network', 'none', '--user', '1000:1000', '--read-only',
       '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true', '--workdir', '/var/www/html']
for src, dst in [(root, '/var/www/html'), (vendor, '/var/www/html/vendor'), (empty_env, '/var/www/html/.env'),
                 (root / 'tools/central-ia/testing/TestCase.php', '/var/www/html/tests/TestCase.php'), (Path(gitdir), gitdir), (Path(node), '/usr/local/bin/node')]:
    cmd += ['--mount', f'type=bind,src={src},dst={dst},readonly']
cmd += ['--mount', f'type=bind,src={pasta},dst=/bateria']
for m in ['/tmp:rw,exec,nosuid,uid=1000,gid=1000,size=128m', '/var/www/html/storage:rw,noexec,nosuid,uid=1000,gid=1000,size=64m',
          '/var/www/html/bootstrap/cache:rw,noexec,nosuid,uid=1000,gid=1000,size=8m']:
    cmd += ['--tmpfs', m]
for v in ['APP_ENV=testing', 'APP_DEBUG=false', 'APP_LOCALE=pt_BR', 'APP_KEY=base64:MTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTE=',
          'DB_CONNECTION=sqlite', 'DB_DATABASE=:memory:', 'BATERIA_FASE=' + fase] + (['BATERIA_AUDIO=' + audio] if audio else []):
    cmd += ['-e', v]
cmd += ['central-alfamatriz-php82:development', 'sh', '-c', 'mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; '
        'exec php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result ' + arquivo]
raise SystemExit(subprocess.run(cmd).returncode)
