#!/bin/bash
# SARAH888 regression. Run from the project root: bash tests/sarah888/run-all.sh
cd "$(dirname "$0")/../.." || exit 1
tp=0; tf=0; s=0
for t in tests/sarah888/*test.php; do
  printf '  %-16s ' "$(basename "$t")"
  R=$(timeout 300 php "$t" 2>&1 | grep -oiE '[0-9]+ passed, *[0-9]+ failed' | tail -1)
  [ -z "$R" ] && { echo "NO SUMMARY"; continue; }
  p=$(echo "$R" | grep -oE '[0-9]+' | head -1); f=$(echo "$R" | grep -oE '[0-9]+' | sed -n 2p)
  tp=$((tp+p)); tf=$((tf+f)); s=$((s+1))
  if [ "$f" -gt 0 ]; then echo "$R   <-- FAILURES"; else echo "$R"; fi
done
echo "-----------------------------------------"
echo "  suites: $s   passed: $tp   failed: $tf"
[ "$tf" -gt 0 ] && exit 1 || exit 0
