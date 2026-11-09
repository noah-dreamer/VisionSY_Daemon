"""Installer regression tests; Docker and package-manager mutations are mocked."""

import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location("visionsy_install", Path(__file__).parents[1] / "install.py")
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class InstallerTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.state = self.root / ".install"
        self.state.mkdir()
        self.env = self.root / ".env.install"
        for name, value in (("ROOT", self.root), ("COMPOSE_DIR", self.root),
                            ("STATE_DIR", self.state), ("ENV_FILE", self.env)):
            fixture = patch.object(installer, name, value)
            fixture.start()
            self.addCleanup(fixture.stop)
        self.calls = []
        self.existing_volumes = ""
        self.admin_exists = False
        self.fail_migration = False
        self.answers = {"bind_ip": "0.0.0.0", "port": "18237",
                        "media": str(self.root / "media space"), "proxies": "127.0.0.1"}

    def fake_run(self, args, **kwargs):
        self.calls.append((args, kwargs))
        output = ""
        if "context" in args:
            output = "unix:///var/run/docker.sock\n"
        elif "version" in args:
            output = "v2.39.0\n"
        elif "ps" in args and "-q" in args:
            output = "test-running-container\n"
        elif "volume" in args:
            output = self.existing_volumes
        elif "network" in args:
            output = json.dumps([{"Subnet": "172.27.0.0/16"}])
        elif "visionsy:ensure-admin" in args:
            output = json.dumps({"changed": not self.admin_exists, "email": "admin@example.com"})
            self.admin_exists = True
        elif "mariadb-dump" in " ".join(args):
            kwargs["stdout"].write("-- successful backup\n")
        if self.fail_migration and "up" in args:
            return subprocess.CompletedProcess(args, 1, "", "test migration failure")
        return subprocess.CompletedProcess(args, 0, output, "")

    def perform_install(self):
        instance = installer.Installer("visionsy-installer-test")
        with patch.object(instance, "configure", return_value=self.answers), \
                patch.object(instance, "check_ports"), \
                patch.object(installer, "admin_password", return_value=("test secret password", True)), \
                patch.object(installer.subprocess, "run", side_effect=self.fake_run), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            instance.install()
        return output.getvalue()

    def test_first_install_starts_single_container_before_seeding_and_passes_password_only_on_stdin(self):
        output = self.perform_install()
        commands = [args for args, _ in self.calls]
        seed = next(index for index, args in enumerate(commands) if "db:seed" in args)
        start = next(index for index, args in enumerate(commands) if "--no-build" in args)
        self.assertLess(start, seed)
        self.assertTrue(all("compose.yaml" not in args for args in commands))
        self.assertFalse(any("run" in args for args in commands))
        self.assertTrue((self.state / "completed").exists())
        self.assertIn("随机密码：test secret password", output)
        self.assertNotIn("test secret password", repr(commands))
        password_call = next(kwargs for args, kwargs in self.calls if "visionsy:ensure-admin" in args)
        self.assertEqual(password_call["input"], "test secret password\n")
        self.assertIn("ProductionSeeder", repr(commands))
        self.assertNotIn("DatabaseSeeder", repr(commands))
        config = installer.read_config(self.env)
        self.assertNotEqual(config["DB_PASSWORD"], config["DB_ROOT_PASSWORD"])
        self.assertEqual(config["DB_HOST"], "127.0.0.1")
        self.assertEqual(stat.S_IMODE(self.env.stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE((self.state / "credentials.txt").stat().st_mode), 0o600)

    def test_repeat_preserves_keys_credentials_and_backs_up_before_stopping_site(self):
        self.perform_install()
        before = installer.read_config(self.env)
        credentials = (self.state / "credentials.txt").read_text()
        self.calls.clear()
        output = self.perform_install()
        after = installer.read_config(self.env)
        for key in ("APP_KEY", "DB_PASSWORD", "DB_ROOT_PASSWORD", "INTERNAL_API_SECRET"):
            self.assertEqual(before[key], after[key])
        self.assertEqual(credentials, (self.state / "credentials.txt").read_text())
        commands = [args for args, _ in self.calls]
        backup = next(index for index, args in enumerate(commands) if "mariadb-dump" in " ".join(args))
        stop = next(index for index, args in enumerate(commands) if "stop" in args)
        self.assertLess(backup, stop)
        self.assertNotIn("随机密码", output)
        self.assertEqual(len(list((self.state / "backups").glob("*.sql"))), 1)

    def test_database_backup_failure_blocks_shutdown_and_container_replacement(self):
        self.perform_install()
        self.calls.clear()
        original = self.fake_run

        def failure(args, **kwargs):
            if "mariadb-dump" in " ".join(args):
                self.calls.append((args, kwargs))
                return subprocess.CompletedProcess(args, 1, "", "backup failed")
            return original(args, **kwargs)

        self.fake_run = failure
        with self.assertRaises(installer.InstallError):
            self.perform_install()
        commands = [args for args, _ in self.calls]
        self.assertFalse(any("stop" in args or "up" in args for args in commands))
        self.assertFalse(list((self.state / "backups").glob("*.sql")))

    def test_previous_workers_are_stopped_before_database(self):
        instance = installer.Installer("visionsy-installer-test")
        calls = []

        def run(args, **kwargs):
            calls.append(args)
            if "ps" in args:
                return args[-1].split("=")[-1] + "-container\n"
            return ""

        with patch.object(instance, "run", side_effect=run):
            instance.stop_previous()
        stopped = [args[-1] for args in calls if "stop" in args]
        self.assertEqual(stopped, ["worker-container", "scheduler-container", "app-container",
                                   "intranet-probe-container", "db-container"])

    def test_orphaned_volumes_block_deployment_before_build_or_key_generation(self):
        self.existing_volumes = "visionsy-installer-test_db_data\n"
        with self.assertRaises(installer.InstallError):
            self.perform_install()
        self.assertFalse(self.env.exists())
        self.assertFalse(any("build" in args for args, _ in self.calls))

    def test_unhealthy_startup_preserves_keys_and_does_not_seed_or_mark_complete(self):
        self.fail_migration = True
        with self.assertRaises(installer.InstallError):
            self.perform_install()
        self.assertTrue(self.env.exists())
        self.assertFalse((self.state / "completed").exists())
        self.assertFalse(any("db:seed" in args for args, _ in self.calls))

    def test_bind_ip_validation_and_literal_configuration_round_trip(self):
        self.assertEqual(installer.validate_bind_ip("0.0.0.0"), "0.0.0.0")
        self.assertEqual(installer.validate_bind_ip("127.0.0.1"), "127.0.0.1")
        for value in ("example.com", "https://example.com", "127.0.0.1:8000", "256.1.2.3", "0.0.0.0\n"):
            with self.subTest(value=value), self.assertRaises(installer.InstallError):
                installer.validate_bind_ip(value)
        config = installer.make_config({}, "visionsy-installer-test", self.answers)
        installer.write_config(self.env, config)
        self.assertEqual(installer.read_config(self.env), config)
        self.assertIn("media space", config["DAM_HOST_MANAGED"])

    def test_shell_environment_cannot_override_installer_database_keys(self):
        instance = installer.Installer("visionsy-installer-test")
        instance.config = {"DB_PASSWORD": "generated", "APP_KEY": "generated"}
        with patch.dict(os.environ, {"DB_PASSWORD": "wrong-host-value", "COMPOSE_FILE": "wrong.yaml"}), \
                patch.object(installer.subprocess, "run", side_effect=self.fake_run):
            instance.run(["docker", "info"])
        self.assertNotIn("DB_PASSWORD", self.calls[0][1]["env"])
        self.assertNotIn("COMPOSE_FILE", self.calls[0][1]["env"])

    def test_password_wizard_generates_on_enter_and_checks_confirmation(self):
        with patch.object(installer.getpass, "getpass", return_value=""):
            password, generated = installer.admin_password()
        self.assertTrue(generated)
        self.assertGreaterEqual(len(password), 24)
        with patch.object(installer.getpass, "getpass", side_effect=["password with $ symbols", "password with $ symbols"]):
            password, generated = installer.admin_password()
        self.assertFalse(generated)
        self.assertEqual(password, "password with $ symbols")

    def test_installer_asks_only_for_bind_address_port_and_media_directory(self):
        instance = installer.Installer("visionsy-installer-test")
        labels = []

        def answer(label, default="", validator=None):
            labels.append(label)
            return str(self.root / "media") if "媒体目录" in label else default

        with patch.object(installer, "prompt", side_effect=answer):
            answers = instance.configure({})
        self.assertEqual(len(labels), 3)
        self.assertEqual(answers["bind_ip"], "0.0.0.0")
        self.assertEqual(answers["port"], "8000")
        self.assertFalse(any(word in " ".join(labels) for word in ("域名", "HTTPS", "证书", "公网")))

    def test_installation_starts_no_proxy_service_or_certificate_commands(self):
        self.perform_install()
        commands = [args for args, _ in self.calls]
        self.assertFalse(any(value in ("edge", "caddy", "https", "--profile", "--resolve")
                             for args in commands for value in args))
        config = installer.read_config(self.env)
        self.assertNotIn("INSTALL_DOMAIN", config)
        self.assertNotIn("INSTALL_MODE", config)
        self.assertNotIn("SESSION_SECURE_COOKIE", config)
        self.assertEqual(config["VISION_AUTH_URL"], "")
        self.assertEqual(config["PUBLIC_STORAGE_URL"], "/storage")

    def test_manual_proxy_settings_are_preserved_without_domain_questions(self):
        config = installer.make_config({}, "visionsy-installer-test", self.answers)
        config.update({"APP_URL": "https://photos.example.com", "SESSION_SECURE_COOKIE": "true",
                       "TRUSTED_PROXY_IPS": "192.0.2.10", "INTRANET_PROBE_URL": "https://probe.example.com"})
        answers = dict(self.answers, proxies=config["TRUSTED_PROXY_IPS"])
        updated = installer.make_config(config, "visionsy-installer-test", answers)
        for key in ("APP_URL", "SESSION_SECURE_COOKIE", "TRUSTED_PROXY_IPS", "INTRANET_PROBE_URL"):
            self.assertEqual(updated[key], config[key])

    def test_direct_access_url_uses_ip_and_port(self):
        with patch.object(installer, "default_ip", return_value="192.0.2.20"):
            self.assertEqual(installer.application_url("0.0.0.0", "8000"), "http://192.0.2.20:8000")
        self.assertEqual(installer.application_url("127.0.0.1", "80"), "http://127.0.0.1")

    def test_busy_port_is_rejected_without_starting_database(self):
        import socket
        with socket.socket() as occupied:
            occupied.bind(("127.0.0.1", 0))
            port = occupied.getsockname()[1]
            instance = installer.Installer("visionsy-installer-test")
            with patch.object(instance, "run", return_value=""), self.assertRaises(installer.InstallError):
                instance.check_ports(dict(self.answers, port=str(port)))

    def test_missing_existing_key_is_not_regenerated(self):
        config = installer.make_config({}, "visionsy-installer-test", self.answers)
        del config["APP_KEY"]
        with self.assertRaises(installer.InstallError):
            installer.make_config(config, "visionsy-installer-test", self.answers)

    def test_nonroot_user_can_delegate_privileged_port_binding_to_docker(self):
        instance = installer.Installer("visionsy-installer-test")
        with patch.object(instance, "run", return_value=""), patch.object(installer.socket, "socket") as socket:
            socket.return_value.__enter__.return_value.bind.side_effect = PermissionError()
            instance.check_ports(dict(self.answers, port="80"))


if __name__ == "__main__":
    unittest.main()
