# Every recipe runs in containers; nothing here touches host toolchains.
# Examples: `just ci`, `just test 8.5`, `CONFORMANCE_CONTEXT=../hushscript-sdk-conformance just conformance`.
set shell := ["bash", "-euo", "pipefail", "-c"]

export HOST_UID := `id -u`
export HOST_GID := `id -g`
# Every scenario must run; an unmapped operation fails conformance.
export CONFORMANCE_STRICT := env("CONFORMANCE_STRICT", "1")
# The conformance suite is read at the ref in conformance-ref, the one place it is pinned.
conformance_ref := `tr -d '[:space:]' < conformance-ref`
export CONFORMANCE_CONTEXT := env("CONFORMANCE_CONTEXT", "https://github.com/Hushscript/hushscript-sdk-conformance.git#" + conformance_ref)

node_image := "node:22-bookworm-slim@sha256:c3de60bf2f9dd0ac6370e6117950ff62d6e339527e7472301c9c78a017978392"
hadolint_image := "hadolint/hadolint:v2.15.1@sha256:32dac94127fd60b7b7e3fbfc65e1383b9b5e25c9bfd7b8536de7a539fe68a12d"
actionlint_image := "rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667"
zizmor_image := "ghcr.io/zizmorcore/zizmor:1.31.1@sha256:42d409dbb851d4c5d35a058cf042b828bea1e3f2884378c526eb0974780e18d9"
shellcheck_image := "koalaman/shellcheck:v0.11.0@sha256:61862eba1fcf09a484ebcc6feea46f1782532571a34ed51fedf90dd25f925a8d"
gitleaks_image := "ghcr.io/gitleaks/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f"
sandbox := "--rm --init --label sdk.test=1 --cap-drop ALL --read-only --security-opt no-new-privileges --pids-limit 512 --memory 4g --tmpfs /tmp"
floor := "8.2"
versions := "8.2 8.3 8.4 8.5"
latest := "8.5"

# unit tests with coverage on PHP 8.2 (default), 8.3, 8.4 or 8.5; no network beyond the mock (the hint tests read the conformance fixtures: CONFORMANCE_DIR, else CONFORMANCE_CONTEXT, else a fetch of the pinned ref)
test version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.3" { "" } else if version == "8.4" { "" } else if version == "8.5" { "" } else { error("version must be 8.2, 8.3, 8.4 or 8.5") } }}
    export PHP_VERSION={{version}}
    CONFORMANCE_DIR="$(scripts/conformance-dir.sh)"
    export CONFORMANCE_DIR
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock test
    docker compose run --rm test

# Infection on the HTTP core and transcribe pipeline (nightly)
mutation version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.3" { "" } else if version == "8.4" { "" } else if version == "8.5" { "" } else { error("version must be 8.2, 8.3, 8.4 or 8.5") } }}
    export PHP_VERSION={{version}}
    export MUTATION_REPORT_DIR="${MUTATION_REPORT_DIR:-$(pwd)/mutation-reports}"
    mkdir -p "$MUTATION_REPORT_DIR"
    CONFORMANCE_DIR="$(scripts/conformance-dir.sh)"
    export CONFORMANCE_DIR
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock mutation
    docker compose run --rm mutation

# the conformance suite against the mock
conformance version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.3" { "" } else if version == "8.4" { "" } else if version == "8.5" { "" } else { error("version must be 8.2, 8.3, 8.4 or 8.5") } }}
    export PHP_VERSION={{version}}
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock conformance
    docker compose run --rm conformance

# every example against the mock
examples version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.3" { "" } else if version == "8.4" { "" } else if version == "8.5" { "" } else { error("version must be 8.2, 8.3, 8.4 or 8.5") } }}
    export PHP_VERSION={{version}}
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock examples
    docker compose run --rm examples

# every static check: pint, phpstan max, psalm taint analysis, composer validate, dependency analyser, hadolint, actionlint, zizmor, shellcheck, gitleaks, typos, composer audit
lint version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.3" { "" } else if version == "8.4" { "" } else if version == "8.5" { "" } else { error("version must be 8.2, 8.3, 8.4 or 8.5") } }}
    export PHP_VERSION={{version}}
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    docker compose build mock lint audit
    docker compose run --rm lint
    run() { docker run {{sandbox}} --network none --user "$(id -u):$(id -g)" -v "$(pwd)":/work:ro -w /work -e HOME=/tmp "$@"; }
    run {{hadolint_image}} /bin/hadolint docker/dev.Dockerfile docker/typos.Dockerfile
    run {{actionlint_image}} -color
    run node:22-bookworm-slim@sha256:c3de60bf2f9dd0ac6370e6117950ff62d6e339527e7472301c9c78a017978392 timeout 60 node --test scripts/release-candidate.test.mjs
    run --label codex.adhoc=1 {{node_image}} timeout 60 node --test scripts/release-candidate.test.mjs
    run {{zizmor_image}} --pedantic --offline --config zizmor.yml .github
    run {{shellcheck_image}} scripts/*.sh
    run {{gitleaks_image}} git --no-banner --redact --verbose /work
    docker build --quiet -f docker/typos.Dockerfile -t hushscript-php-typos:local docker
    run hushscript-php-typos:local --config _typos.toml
    # The one lint step with network access: it asks the advisory database about the locked set, on the newest
    # PHP only (the package ships source; older runtimes are the consumer's to patch).
    if [ "{{version}}" = "{{latest}}" ]; then docker compose run --rm audit; fi

# composer validate, then an archive of the package installed next to the runtime dependencies only (no network) and loaded
build version=floor:
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "8.2" { "" } else if version == "8.3" { "" } else if version == "8.4" { "" } else if version == "8.5" { "" } else { error("version must be 8.2, 8.3, 8.4 or 8.5") } }}
    export PHP_VERSION={{version}}
    docker build --quiet --target dev --build-arg PHP_VERSION={{version}} -f docker/dev.Dockerfile -t "hushscript-php-dev:php{{version}}" .
    docker build --quiet --target proddeps --build-arg PHP_VERSION={{version}} -f docker/dev.Dockerfile -t "hushscript-php-proddeps:php{{version}}" .
    out="$(mktemp -d)"
    trap 'rm -rf "$out"' EXIT
    chmod 777 "$out"
    user="$(id -u):$(id -g)"
    docker run {{sandbox}} --network none --user "$user" -e HOME=/tmp -v "$out":/out "hushscript-php-dev:php{{version}}" \
      sh -c 'timeout 120 composer --no-plugins validate --strict && timeout 120 composer --no-plugins archive --format=tar --dir=/out --file=hushscript'
    check='require "/work/vendor/autoload.php"; spl_autoload_register(static function (string $c): void { $f = "/tmp/pkg/src/" . str_replace("\\", "/", substr($c, 10)) . ".php"; if (str_starts_with($c, "Hushscript\\") && is_file($f)) { require $f; } }); $hs = new Hushscript\Client("hsr1:eu:pat.0123456789abcdef0123456789abcdef.0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"); assert(Hushscript\Generated\Catalog::ENTRIES !== [] && Hushscript\Generated\Hints::TABLE !== [] && Hushscript\Generated\Scopes::OPERATIONS !== []); echo "archive ok ", Hushscript\Version::SDK, "\n";'
    docker run {{sandbox}} --network none --user "$user" -v "$out":/dist:ro -w /tmp "hushscript-php-proddeps:php{{version}}" \
      sh -c 'mkdir /tmp/pkg && cd /tmp/pkg && tar -xf /dist/hushscript.tar -C /tmp/pkg && test -f /tmp/pkg/src/Client.php && test ! -e /tmp/pkg/tests && cd /work && timeout 60 php -d zend.assertions=1 -r "$0"' "$check"

# regenerate openapi.json and src/Generated from the production spec, or from a local spec file (outbound network)
regen spec="":
    #!/usr/bin/env bash
    set -euo pipefail
    export PHP_VERSION={{floor}}
    trap 'docker compose down --remove-orphans >/dev/null 2>&1 || true' EXIT
    extra=()
    if [ -n "${CONFORMANCE_DIR:-}" ]; then
      extra=(-v "$(realpath "$CONFORMANCE_DIR")":/conformance:ro -e CONFORMANCE_DIR=/conformance)
    fi
    spec="{{spec}}"
    # A local file is bind-mounted, so the generator reads exactly the bytes that were diffed.
    if [ -n "$spec" ] && [ -f "$spec" ]; then
      extra+=(-v "$(realpath "$spec")":/spec/openapi.json:ro)
      spec=/spec/openapi.json
    fi
    docker compose build regen
    docker compose run --rm ${extra[@]+"${extra[@]}"} regen timeout 600 php scripts/generate.php ${spec:+"$spec"}

# every gate on PHP 8.2, 8.3, 8.4 and 8.5 by default, or on one requested version
ci version="":
    #!/usr/bin/env bash
    set -euo pipefail
    {{ if version == "" { "" } else if version == "8.2" { "" } else if version == "8.3" { "" } else if version == "8.4" { "" } else if version == "8.5" { "" } else { error("version must be 8.2, 8.3, 8.4 or 8.5") } }}
    if [ -n "{{version}}" ]; then versions="{{version}}"; else versions="{{versions}}"; fi
    for v in $versions; do
      just lint "$v"
      just test "$v"
      just conformance "$v"
      just examples "$v"
      just build "$v"
    done

# nightly smoke against production; needs SMOKE_TOKEN (outbound network)
smoke:
    #!/usr/bin/env bash
    set -euo pipefail
    : "${SMOKE_TOKEN:?set SMOKE_TOKEN to a token that can read the account}"
    export PHP_VERSION={{floor}}
    docker compose build smoke
    docker compose run --rm smoke
