#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
module_dir="$project_dir/htdocs/custom/bankconnect"
version="$(sed -n "s/.*\$this->version = '\([^']*\)'.*/\1/p" "$module_dir/core/modules/modBankConnect.class.php" | head -n 1)"

if [[ -z "$version" ]]; then
	echo "Unable to read module version" >&2
	exit 1
fi

for required in \
	"core/modules/modBankConnect.class.php" \
	"admin/bankconnect.php" \
	"langs/da_DK/bankconnect.lang" \
	"langs/en_US/bankconnect.lang" \
	"sql/llx_bankconnect_transaction.sql"; do
	if [[ ! -f "$module_dir/$required" ]]; then
		echo "Missing required module file: $required" >&2
		exit 1
	fi
done

use_python_zip=0
if ! command -v zip >/dev/null 2>&1; then
	if command -v python3 >/dev/null 2>&1; then
		use_python_zip=1
	else
		echo "The zip command (or python3) is required to build the Dolibarr package" >&2
		exit 1
	fi
fi

dist_dir="$project_dir/dist"
stage_dir="$(mktemp -d "$project_dir/.bankconnect-build.XXXXXX")"
trap 'rm -rf "$stage_dir"' EXIT

mkdir -p "$dist_dir" "$stage_dir/bankconnect"
cp -R "$module_dir/." "$stage_dir/bankconnect/"
# The legacy prototype writes directly to llx_bank and is intentionally not
# part of an installable release. It remains in the repository until the
# standard Dolibarr Account/Paiement adapter replaces it.
cp "$project_dir/README.md" "$stage_dir/bankconnect/README.md"
cp "$project_dir/CHANGELOG.md" "$stage_dir/bankconnect/CHANGELOG.md"

package="$dist_dir/module_bankconnect-${version}.zip"
rm -f "$package"
(
	cd "$stage_dir"
	if [[ "$use_python_zip" == 1 ]]; then
		python3 -c 'import sys,zipfile,pathlib; zf=zipfile.ZipFile(sys.argv[1],"w",zipfile.ZIP_DEFLATED); pkg=pathlib.Path("bankconnect"); [zf.write(f,arcname=str(f)) for f in sorted(pkg.rglob("*")) if f.is_file()]; zf.close()' "$package"
	else
		zip -qr "$package" bankconnect
	fi
)

echo "$package"
