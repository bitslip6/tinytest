#!/usr/bin/env bash
# Linux-only isolated acceptance probe. Never starts/reloads system services.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
fpm_bin=${FPM_BIN:-php-fpm}
fastcgi_params=${FASTCGI_PARAMS:-/etc/nginx/fastcgi_params}
port=${FPM_TEST_PORT:-8099}
[[ "$port" =~ ^[0-9]+$ ]] && (( port > 1024 && port < 65536 ))
for command in "$fpm_bin" nginx curl python3 setsid; do command -v "$command" >/dev/null; done
[[ -r "$fastcgi_params" ]]
(( EUID != 0 )) || { echo 'Run the isolated probe as a non-root user' >&2; exit 1; }

output=${1:-$root/fpm-artifacts}
mkdir -p "$output"
output=$(cd "$output" && pwd)
[[ -z "$(ls -A "$output")" ]] || { echo 'Use an empty diagnostic output directory' >&2; exit 1; }

# Short paths avoid Unix socket path limits. Configs contain a token and are
# private, temporary, and deliberately excluded from diagnostic artifacts.
work=$(mktemp -d /tmp/tinytest-fpm.XXXXXX)
fpm_pid=''
nginx_pid=''
cleanup() {
  local status=$?
  trap - EXIT INT TERM
  # Each service has its own process group, including all pool/worker children.
  for pid in "$nginx_pid" "$fpm_pid"; do
    if [[ -n "$pid" ]]; then kill -TERM -- "-$pid" 2>/dev/null || true; fi
  done
  for attempt in {1..30}; do
    local alive=0
    for pid in "$nginx_pid" "$fpm_pid"; do
      if [[ -n "$pid" ]] && kill -0 -- "-$pid" 2>/dev/null; then alive=1; fi
    done
    [[ "$alive" == 0 ]] && break
    sleep 0.1
  done
  for pid in "$nginx_pid" "$fpm_pid"; do
    if [[ -n "$pid" ]]; then
      kill -KILL -- "-$pid" 2>/dev/null || true
      wait "$pid" 2>/dev/null || true
    fi
  done
  if [[ -d "$work/state" ]]; then cp -R "$work/state" "$output/state" || status=1; fi
  rm -rf "$work" || status=1
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$work/state"

python3 - "$root" "$work" "$output" "$fastcgi_params" "$port" <<'PY'
import grp, os, pathlib, pwd, secrets, sys
root, work, output, params, port = map(pathlib.Path, sys.argv[1:])
# These values enter nginx/FPM configuration syntax. Reject unusual local paths
# rather than silently generating invalid or injected configuration directives.
for path in (root, work, output, params):
    if any(c in str(path) for c in '\n\r";{}$') or any(c.isspace() for c in str(path)):
        raise SystemExit('Configuration paths must not contain whitespace or metacharacters')
token = secrets.token_hex(32)
(work / 'token').write_text(token)
pools = (root / 'php-fpm-tests/php-fpm.conf.example').read_text()
user = pwd.getpwuid(os.getuid()).pw_name
pools = pools.replace('www-data', user)
# Group can differ from the login name (including listen.group).
group = grp.getgrgid(os.getgid()).gr_name
pools = pools.replace('group = ' + user, 'group = ' + group)
pools = pools.replace('/run/php/tinytest-e2e-driver.sock', str(work / 'driver.sock'))
pools = pools.replace('/run/php/tinytest-e2e-worker.sock', str(work / 'worker.sock'))
pools = pools.replace('/var/tmp/tinytest-fpm-tests', str(work / 'state'))
pools = pools.replace('REPLACE_WITH_SAME_RANDOM_TOKEN_AT_LEAST_32_CHARACTERS', token)
pools = pools.replace('127.0.0.1:8099', '127.0.0.1:' + str(port))
(work / 'fpm.conf').write_text('[global]\npid = ' + str(work / 'fpm.pid')
    + '\nerror_log = ' + str(output / 'fpm-error.log') + '\nlog_level = notice\n' + pools)
server = (root / 'php-fpm-tests/nginx.conf.example').read_text()
server = server.replace('/absolute/path/to/tinytest', str(root))
server = server.replace('/run/php/tinytest-e2e-driver.sock', str(work / 'driver.sock'))
server = server.replace('/run/php/tinytest-e2e-worker.sock', str(work / 'worker.sock'))
server = server.replace('include fastcgi_params;', 'include ' + str(params) + ';')
server = server.replace('127.0.0.1:8099', '127.0.0.1:' + str(port))
(work / 'nginx.conf').write_text('pid ' + str(work / 'nginx.pid') + ';\n'
    + 'error_log ' + str(output / 'nginx-error.log') + ';\nevents {}\nhttp {\n'
    + 'access_log ' + str(output / 'nginx-access.log') + ';\n'
    + 'client_body_temp_path ' + str(work / 'client-body') + ';\n'
    + 'fastcgi_temp_path ' + str(work / 'fastcgi-temp') + ';\n' + server + '\n}\n')
PY

"$fpm_bin" -v
nginx -v
"$fpm_bin" -t -y "$work/fpm.conf" >"$output/fpm-config-test.log" 2>&1
nginx -t -p "$work/" -c "$work/nginx.conf" >"$output/nginx-config-test.log" 2>&1
setsid "$fpm_bin" -F -y "$work/fpm.conf" >"$output/fpm-console.log" 2>&1 &
fpm_pid=$!
setsid nginx -p "$work/" -c "$work/nginx.conf" -g 'daemon off;' >"$output/nginx-console.log" 2>&1 &
nginx_pid=$!

ready=0
for attempt in {1..100}; do
  kill -0 "$fpm_pid" && kill -0 "$nginx_pid"
  if curl --silent --fail --max-time 1 "http://127.0.0.1:$port/fpm-tests" >/dev/null; then ready=1; break; fi
  sleep 0.1
done
[[ "$ready" == 1 ]] || { echo 'nginx/FPM did not become ready' >&2; exit 1; }
# Keep credentials out of process arguments and logs.
printf 'header = "Authorization: Bearer %s"\n' "$(<"$work/token")" > "$work/curl.conf"
curl --silent --show-error --fail-with-body --max-time 70 --config "$work/curl.conf" \
  -X POST "http://127.0.0.1:$port/fpm-tests" -o "$output/report.json"

python3 - "$output" <<'PY'
import json, pathlib, re, sys
output = pathlib.Path(sys.argv[1])
report = json.loads((output / 'report.json').read_text())
checks = report.get('checks', [])
for check in checks:
    print(('PASS' if check.get('ok') is True else 'FAIL') + ': ' + str(check.get('name')))
if report.get('ok') is not True or not checks or any(c.get('ok') is not True for c in checks):
    raise SystemExit('FPM acceptance probe failed; inspect report.json and logs')
heartbeat = next(c for c in checks if c['name'] == 'worker_heartbeat_stopped')
pid = str(heartbeat['detail']['pid']).strip()
if not pid.isdecimal():
    raise SystemExit('Probe did not return a valid hanging-worker PID')
log = (output / 'fpm-error.log').read_text()
# Require the specific worker/PID and FPM's timeout decision, not just a
# stopped heartbeat or a client/gateway timeout.
pattern = r'\[pool tinytest-e2e-worker\] child ' + re.escape(pid) + r', .*execution timed out.*terminating'
if not re.search(pattern, log):
    raise SystemExit('Missing FPM request timeout/termination log for worker PID ' + pid)
print('PASS: FPM log confirms timeout termination of worker PID ' + pid)
(output / 'termination-confirmed.txt').write_text('FPM timeout confirmed for worker PID ' + pid + '\n')
PY
