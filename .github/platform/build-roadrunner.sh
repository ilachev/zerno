#!/usr/bin/env bash
# CI-only external platform preparation; run from the checkout root on Linux amd64.
set -euo pipefail

export GOTOOLCHAIN=local
export GOWORK=off
export CGO_ENABLED=0
export GOOS=linux
export GOARCH=amd64
export GOPROXY=https://proxy.golang.org
export GOSUMDB=sum.golang.org

mkdir -p var/security/bin var/security/platform/source
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
  --max-time 120 --retry 2 \
  --output var/security/platform/go.tar.gz \
  https://go.dev/dl/go1.27.4.linux-amd64.tar.gz
echo '7226736c2339719cda28fbf3a54d71aa63bcefe376e98cef00034268dab24840  var/security/platform/go.tar.gz' > var/security/platform-inputs.sha256.txt
sha256sum --check --strict var/security/platform-inputs.sha256.txt
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
  --max-time 120 --retry 2 \
  --output var/security/platform/source.tar.gz \
  https://api.github.com/repos/roadrunner-server/roadrunner/tarball/ed89c2ec991f64b072297fcbee5ad7f058779199
echo 'b27ce771605a203a0119747fb9d71f4aa2997e24227a3861e9e3b63acc3dd624  var/security/platform/source.tar.gz' >> var/security/platform-inputs.sha256.txt
sha256sum --check --strict var/security/platform-inputs.sha256.txt
tar -xzf var/security/platform/go.tar.gz -C var/security/platform
tar -xzf var/security/platform/source.tar.gz --strip-components=1 -C var/security/platform/source
patch --batch --fuzz=0 -p1 -d var/security/platform/source < .github/platform/roadrunner-security.patch
cat >> var/security/platform-inputs.sha256.txt <<'HASHES'
4f86f69390be87e9e3b4d920969d428e7914950d6b284cdbf5c8ff5ac15ccbe7  var/security/platform/source/go.mod
5acbc6a5b97a8a190515344b1e3d1ae50629b38bddfb38fcc7c4368d3fafad00  var/security/platform/source/go.sum
HASHES
sha256sum --check --strict var/security/platform-inputs.sha256.txt
(
  cd var/security/platform/source
  ../go/bin/go version
  ../go/bin/go mod download
  ../go/bin/go mod verify
  ../go/bin/go build -p=2 -mod=readonly -trimpath -buildvcs=false \
    -ldflags '-s -X github.com/roadrunner-server/roadrunner/v3/internal/meta.version=3.0.1 -X github.com/roadrunner-server/roadrunner/v3/internal/meta.buildTime=2026-11-13T00:00:00Z' \
    -o ../../bin/rr ./cmd/rr
)
sha256sum --check --strict var/security/platform-inputs.sha256.txt
var/security/platform/go/bin/go version -m -json var/security/bin/rr > var/security/platform-buildinfo.json
sha256sum var/security/bin/rr > var/security/platform-sha256.txt
