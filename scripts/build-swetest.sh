#!/usr/bin/env bash
#
# Builds the Swiss Ephemeris `swetest` program on Linux from its official
# sources, pinned to one commit, and puts it with the three data files
# AstroLabe uses (planets, Moon, Chiron; 1800–2400) into a directory:
#
#   SWISSEPH_SOURCE=<repository URL> bash scripts/build-swetest.sh <directory>
#
#   <directory>/swetest
#   <directory>/ephe/sepl_18.se1, semo_18.se1, seas_18.se1
#
# Then set SWETEST_PATH=<directory>/swetest and EPHEMERIS_PATH=<directory>/ephe.
#
# The repository URL is the one the Swiss Ephemeris licence contract names; it
# is kept out of this repository (CI: the SWISSEPH_SOURCE secret). Only the
# files needed are fetched (~1.8 MB of sources and ~2 MB of data), never the
# whole repository. Git checks every file against the pinned commit, and the
# data files are checked again against the SHA-256 of the copies the app was
# tested with. Needs git, make and a C compiler (build-essential).

set -euo pipefail

target="${1:?Usage: SWISSEPH_SOURCE=<repository URL> $0 <directory>}"

# Moving to another commit: change it here, run the reference tests
# (SwissEphemerisReferenceTest, HouseAccuracyTest) and note it in docs/deployment.md.
commit=aacf962d19d79f8bc921dbcdaacf306d85be1917

if [ -z "${SWISSEPH_SOURCE:-}" ]; then
    echo "SWISSEPH_SOURCE is not set: the repository URL from the Swiss Ephemeris licence contract." >&2
    exit 2
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

git -C "$work" init --quiet
git -C "$work" remote add origin "$SWISSEPH_SOURCE"
# A partial, sparse checkout: blobs are fetched only for the paths below.
git -C "$work" config remote.origin.promisor true
git -C "$work" config remote.origin.partialclonefilter blob:none
git -C "$work" config core.sparseCheckout true
cat > "$work/.git/info/sparse-checkout" <<'PATHS'
/*.c
/*.h
/Makefile
/LICENSE
/ephe/sepl_18.se1
/ephe/semo_18.se1
/ephe/seas_18.se1
PATHS

git -C "$work" fetch --quiet --depth 1 --filter=blob:none origin "$commit"
git -C "$work" -c advice.detachedHead=false checkout --quiet FETCH_HEAD

if [ "$(git -C "$work" rev-parse HEAD)" != "$commit" ]; then
    echo "The checked-out commit is not $commit." >&2
    exit 1
fi

(
    cd "$work/ephe"
    sha256sum --check --quiet <<'SUMS'
ca1393ceab3a44fbc895887cf789c68819ae6a1cbc9b22225872dbe4ccd99a66  sepl_18.se1
1ca07bd67c24374d77226180c20a4f9996cba013697894810518e7eb582ca4f7  semo_18.se1
a2cd8fc33807c78ca9a700c91c2e042258b12fc4796519e00781440b5ad8b2e2  seas_18.se1
SUMS
)

make -C "$work" --quiet swetest >/dev/null

mkdir -p "$target/ephe"
install -m 0755 "$work/swetest" "$target/swetest"
install -m 0644 "$work"/ephe/sepl_18.se1 "$work"/ephe/semo_18.se1 "$work"/ephe/seas_18.se1 "$target/ephe/"
# The licence travels with the program (contract: copyright notices stay).
install -m 0644 "$work/LICENSE" "$target/LICENSE"

"$target/swetest" -h | grep -m1 -i 'version' || true
echo "swetest built from commit ${commit:0:7} into $target"
