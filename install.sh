#!/usr/bin/env bash
# Installs the Patchline PHAR from the latest (or a pinned) GitHub release.
#
#   curl -fsSL https://github.com/pushonltd/magento-patchline/releases/latest/download/install.sh | bash
#   curl -fsSL .../install.sh | bash -s -- --version=v1.2.3 --path=/usr/local/bin/patchline
#
# Options (flags win over the environment variables):
#   --version=TAG   PATCHLINE_VERSION       release to install, with or without the leading "v" (default: latest)
#   --path=FILE     PATCHLINE_INSTALL_PATH  file to write (default: ./patchline.phar)
#   PATCHLINE_DOWNLOAD_URL                  base URL of the releases, for tests only
set -euo pipefail

# Everything lives in a function that is called on the last line: a truncated download runs nothing.
main() {
    local version="${PATCHLINE_VERSION:-}"
    local target="${PATCHLINE_INSTALL_PATH:-./patchline.phar}"
    local base="${PATCHLINE_DOWNLOAD_URL:-https://github.com/pushonltd/magento-patchline/releases}"
    local repo="pushonltd/magento-patchline"
    local arg
    for arg in "$@"; do
        case "$arg" in
            --version=*) version="${arg#--version=}" ;;
            --path=*) target="${arg#--path=}" ;;
            *) fail "Unknown option: $arg. Supported: --version=TAG, --path=FILE." ;;
        esac
    done

    local url="$base/latest/download"
    if [ -n "$version" ]; then
        url="$base/download/v${version#v}"
    fi

    command -v curl >/dev/null 2>&1 || fail "curl is required. Install curl and run this again."

    local dir part workdir
    dir="$(dirname "$target")"
    [ -d "$dir" ] || fail "The directory $dir does not exist. Create it or choose another --path."
    [ -w "$dir" ] || fail "Cannot write to $dir. Choose a writable --path, or run this again with sudo."
    part="$target.part"
    workdir="$(mktemp -d)"
    # shellcheck disable=SC2064 # the paths are fixed now, on purpose
    trap "rm -rf '$workdir' '$part'" EXIT

    echo "Downloading patchline.phar from $url ..."
    curl -fsSL -o "$part" "$url/patchline.phar" || fail "Could not download $url/patchline.phar."
    curl -fsSL -o "$workdir/patchline.phar.sha256" "$url/patchline.phar.sha256" \
        || fail "Could not download $url/patchline.phar.sha256."

    verify_checksum "$part" "$workdir/patchline.phar.sha256"
    verify_provenance "$part" "$repo"

    chmod +x "$part"
    mv -f "$part" "$target"
    echo "Installed $target"
    if command -v php >/dev/null 2>&1; then
        php "$target" --version
    fi
    echo "Next: run \"php $target status\" in your Magento root (or \"patchline status\" if it is on your PATH)."
}

fail() {
    echo "Error: $1" >&2
    exit 1
}

sha256_of() {
    local out
    if command -v sha256sum >/dev/null 2>&1; then
        out="$(sha256sum "$1")"
    elif command -v shasum >/dev/null 2>&1; then
        out="$(shasum -a 256 "$1")"
    else
        fail "sha256sum or shasum is required to verify the download."
    fi
    echo "${out%% *}"
}

verify_checksum() {
    local file="$1" sumfile="$2" expected="" actual
    read -r expected _ < "$sumfile" || true
    [[ "$expected" =~ ^[0-9a-fA-F]{64}$ ]] \
        || fail "The checksum file is empty or malformed. Nothing was installed."
    actual="$(sha256_of "$file")"
    [ "$(echo "$actual" | tr 'A-F' 'a-f')" = "$(echo "$expected" | tr 'A-F' 'a-f')" ] \
        || fail "Checksum mismatch: the download is not the released file. Nothing was installed."
    echo "Checksum verified."
}

verify_provenance() {
    local file="$1" repo="$2"
    if ! command -v gh >/dev/null 2>&1; then
        echo "Provenance not checked: install the GitHub CLI (gh) to verify where this file was built."
        return 0
    fi
    if ! gh auth status >/dev/null 2>&1; then
        echo "Provenance check skipped: gh is not logged in. Run \"gh auth login\" to enable it."
        return 0
    fi
    gh attestation verify "$file" -R "$repo" \
        --signer-workflow "$repo/.github/workflows/release.yml" >/dev/null \
        || fail "Build provenance verification failed. Nothing was installed."
    echo "Build provenance verified."
}

main "$@"
