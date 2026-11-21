#!/usr/bin/env bash
# Runs each engine in fresh PHP processes with opcache's file cache (like a warm web server),
# then prints medians. Usage: benchmarks/bench.sh [runs] [iterations] [invoice|report|arabic]
set -euo pipefail
cd "$(dirname "$0")"
RUNS=${1:-5}
ITER=${2:-10}
DOC=${3:-invoice}
# The invoice keeps its original file names; the other documents are prefixed with their name.
P=$([ "$DOC" = invoice ] && echo "" || echo "$DOC-")
TYP=$([ "$DOC" = invoice ] && echo invoice.typ || echo "docs/$DOC.typ")
mkdir -p out cache/opcache
PHP=(php -d opcache.enable_cli=1 -d opcache.file_cache="$PWD/cache/opcache" -d opcache.file_cache_only=0 -d memory_limit=1G -d display_errors=stderr)

for engine in yetipdf dompdf mpdf tcpdf; do
  # Warm-up run: builds font caches and opcache files, and writes the sample PDF.
  "${PHP[@]}" runner.php "$engine" 1 "out/$P$engine.pdf" "$DOC" > /dev/null 2>&1
  for ((i = 0; i < RUNS; i++)); do
    "${PHP[@]}" runner.php "$engine" "$ITER" - "$DOC" 2>/dev/null
  done > "out/$P$engine.jsonl"
done

if command -v typst > /dev/null; then
  typst_times=()
  for ((i = 0; i < RUNS; i++)); do
    s=$(perl -MTime::HiRes=time -e 'print time')
    typst compile --root .. --font-path ../fonts --font-path ../tests/fixtures/fonts --ignore-system-fonts "$TYP" "out/${P}typst.pdf"
    e=$(perl -MTime::HiRes=time -e 'print time')
    typst_times+=("$(perl -e "printf '%.1f', ($e - $s) * 1000")")
  done
  printf '%s\n' "${typst_times[@]}" > "out/${P}typst.times"
fi

P="$P" php -r '
$p = getenv("P");
$rows = [];
foreach (["yetipdf", "dompdf", "mpdf", "tcpdf"] as $e) {
  $r = array_map(fn ($l) => json_decode($l, true), file("out/$p$e.jsonl", FILE_IGNORE_NEW_LINES));
  $med = function ($k) use ($r) { $v = array_column($r, $k); sort($v); return $v[intdiv(count($v), 2)]; };
  printf("%-8s first render %8.1f ms   warm %8.1f ms   peak %6.1f MB   %6.1f KB   %d pages\n", $e, $med("first_ms"), $med("warm_ms"), $med("peak_mb"), $r[0]["kb"], $r[0]["pages"]);
}
if (is_file("out/{$p}typst.times")) { $t = file("out/{$p}typst.times", FILE_IGNORE_NEW_LINES); sort($t); printf("%-8s whole CLI process %5.1f ms (Rust, for reference)\n", "typst", $t[intdiv(count($t), 2)]); }
'
