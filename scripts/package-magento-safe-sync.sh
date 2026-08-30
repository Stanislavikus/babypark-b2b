#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_dir="${1:-$repo_root/build/magento-safe-sync}"
dist_url="${2:-https://certification.invalid/b2b-platform-magento-safe-sync-0.2.1.zip}"
module_path="integrations/magento-safe-sync"
version="$(sed -n 's/.*setup_version="\([^"]*\)".*/\1/p' "$repo_root/$module_path/etc/module.xml")"

if [[ -z "$version" ]]; then
    echo "Unable to resolve the Magento module version." >&2
    exit 1
fi

mkdir -p "$output_dir"
artifact="$output_dir/b2b-platform-magento-safe-sync-$version.zip"

git -C "$repo_root" archive --format=zip --output="$artifact" "HEAD:$module_path"
sha256="$(sha256sum "$artifact" | cut -d' ' -f1)"
sha1="$(sha1sum "$artifact" | cut -d' ' -f1)"

php "$repo_root/scripts/build-magento-safe-sync-repository.php" \
    "$repo_root/$module_path/composer.json" \
    "$version" \
    "$dist_url" \
    "$sha1" \
    "$output_dir/packages.json"

printf 'Artifact: %s\nRepository metadata: %s\nSHA-256: %s\n' "$artifact" "$output_dir/packages.json" "$sha256"
