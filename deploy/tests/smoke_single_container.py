#!/usr/bin/env python3
"""Build first: docker build -t visionsy-app:single-container-test web
Run from any directory. Creates an isolated project; deletes only its own test volumes.
"""
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
PROJECT = 'visionsy-smoke-' + secrets.token_hex(4)


def main():
    with tempfile.TemporaryDirectory(prefix='visionsy-single-smoke-') as temporary:
        directory = Path(temporary)
        media = directory / 'media'
        media.mkdir()
        env_file = directory / 'smoke.env'
        env_file.write_text('\n'.join([
            'APP_IMAGE_TAG=single-container-test',
            'APP_KEY=base64:' + __import__('base64').b64encode(secrets.token_bytes(32)).decode(),
            'APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=http://localhost',
            'DB_CONNECTION=mysql', 'DB_HOST=127.0.0.1', 'DB_PORT=3306',
            'DB_DATABASE=visionsy', 'DB_USERNAME=visionsy',
            'DB_PASSWORD=' + secrets.token_hex(24), 'DB_ROOT_PASSWORD=' + secrets.token_hex(24),
            'INTERNAL_API_SECRET=' + secrets.token_hex(24),
            'SESSION_DRIVER=database', 'CACHE_STORE=database', 'QUEUE_CONNECTION=database',
            'DB_QUEUE_RETRY_AFTER=3700', 'LOG_CHANNEL=stderr',
            'DAM_HOST_MANAGED=' + str(media), 'MEDIA_MANAGED_ROOT=/data/visionsy/managed',
            'MEDIA_ALLOWED_ROOTS=/data/visionsy/managed',
        ]) + '\n')
        env_file.chmod(0o600)
        override = directory / 'override.yaml'
        override.write_text('services:\n  app:\n    env_file: !override\n      - ' + str(env_file) +
                            '\n    ports: !override\n      - "127.0.0.1::80"\n')
        environment = {key: value for key, value in os.environ.items()
                       if not key.startswith(('APP_', 'DB_', 'MARIADB_', 'DAM_', 'COMPOSE_'))}
        command = ['docker', 'compose', '-p', PROJECT, '-f', str(ROOT / 'deploy/docker/compose.prod.yaml'),
                   '-f', str(override), '--env-file', str(env_file)]

        def dc(*args, check=True):
            result = subprocess.run(command + list(args), env=environment, text=True, capture_output=True)
            if check and result.returncode:
                raise RuntimeError(result.stderr + result.stdout)
            return result

        def php(code):
            return dc('exec', '-T', 'app', 'php', '-r', code).stdout.strip()

        def control(*args, check=True):
            return dc('exec', '-T', 'app', 'supervisorctl', '-c', '/etc/visionsy/supervisord.conf',
                      *args, check=check)

        def healthy():
            for _ in range(60):
                if dc('exec', '-T', 'app', '/etc/visionsy/healthcheck.sh', check=False).returncode == 0:
                    return
                time.sleep(2)
            raise AssertionError('Services did not recover')

        try:
            # Simulate the existing multi-container layout with the official 11.4 server.
            # The new app must be able to reuse this exact named data volume.
            legacy = directory / 'legacy.yaml'
            legacy.write_text('services:\n  db:\n    image: mariadb:11.4\n'
                              '    environment:\n'
                              '      MARIADB_DATABASE: visionsy\n'
                              '      MARIADB_USER: visionsy\n'
                              '      MARIADB_PASSWORD: ${DB_PASSWORD}\n'
                              '      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}\n'
                              '    volumes:\n      - db_data:/var/lib/mysql\n'
                              '    healthcheck:\n'
                              '      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]\n'
                              '      interval: 2s\n      timeout: 5s\n      retries: 60\n'
                              'volumes:\n  db_data:\n')
            legacy_command = ['docker', 'compose', '-p', PROJECT, '-f', str(legacy),
                              '--env-file', str(env_file)]

            def old(*args):
                result = subprocess.run(legacy_command + list(args), env=environment, text=True, capture_output=True)
                if result.returncode:
                    raise RuntimeError(result.stderr + result.stdout)
                return result

            old('up', '-d', '--wait', '--wait-timeout', '180')
            old('exec', '-T', 'db', 'sh', '-c',
                'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -u root visionsy -e '
                '\'CREATE TABLE legacy_sentinel (id INT PRIMARY KEY); INSERT INTO legacy_sentinel VALUES (99);\'')
            old('stop', 'db')
            old('rm', '-f', 'db')
            dc('up', '-d', '--no-build', '--wait', '--wait-timeout', '300')
            containers = json.loads(dc('ps', '--format', 'json').stdout)
            if isinstance(containers, dict):
                containers = [containers]
            assert len(containers) == 1, containers
            assert containers[0]['Service'] == 'app'
            healthy()
            address = dc('port', 'app', '80').stdout.strip()
            with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(
                    'http://' + address + '/login', timeout=10) as response:
                assert response.status == 200
            print('PASS: exactly one container, all four services healthy, login responds')
            connection = '$db=new PDO("mysql:host=127.0.0.1;dbname=visionsy",getenv("DB_USERNAME"),getenv("DB_PASSWORD"));'
            assert php(connection + 'echo $db->query("SELECT id FROM legacy_sentinel")->fetchColumn();') == '99'
            print('PASS: reuses the previous MariaDB 11.4 data volume with existing records')
            assert php(connection + 'echo $db->query("SELECT @@bind_address")->fetchColumn();') == '127.0.0.1'
            php(connection + '$db->exec("CREATE TABLE smoke_persistence (id INT PRIMARY KEY)");'
                '$db->exec("INSERT INTO smoke_persistence VALUES (42)");')
            dc('exec', '-T', 'app', 'sh', '-c', 'echo smoke > /app/storage/app/smoke-persistence')
            (media / 'smoke-persistence').write_text('media\n')
            old_pid = control('pid', 'application:worker').stdout.strip()
            dc('exec', '-T', 'app', 'kill', '-KILL', old_pid)
            healthy()
            assert control('pid', 'application:worker').stdout.strip() != old_pid
            print('PASS: failed queue process restarts automatically')
            control('stop', 'application:worker')
            assert dc('exec', '-T', 'app', '/etc/visionsy/healthcheck.sh', check=False).returncode != 0
            control('start', 'application:worker')
            healthy()
            print('PASS: healthcheck detects a stopped background service')
            dc('restart', 'app')
            healthy()
            assert php(connection + 'echo $db->query("SELECT id FROM smoke_persistence")->fetchColumn();') == '42'
            assert dc('exec', '-T', 'app', 'cat', '/app/storage/app/smoke-persistence').stdout.strip() == 'smoke'
            assert dc('exec', '-T', 'app', 'cat', '/data/visionsy/managed/smoke-persistence').stdout.strip() == 'media'
            dc('up', '-d', '--no-build', '--force-recreate', '--wait', '--wait-timeout', '300')
            healthy()
            assert php(connection + 'echo $db->query("SELECT id FROM smoke_persistence")->fetchColumn();') == '42'
            assert dc('exec', '-T', 'app', 'cat', '/app/storage/app/smoke-persistence').stdout.strip() == 'smoke'
            print('PASS: database, storage and media survive restart and container replacement')
        except Exception:
            print(dc('logs', '--tail', '100', check=False).stdout)
            raise
        finally:
            dc('down', '--volumes', '--remove-orphans')


if __name__ == '__main__':
    main()
