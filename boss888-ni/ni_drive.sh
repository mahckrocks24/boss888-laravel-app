#!/bin/bash
# usage: ni_drive.sh OUTFILE START_QID END_QID
set -u
OUT="${1:-/tmp/ni_baseline.jsonl}"
START="${2:-1}"
END="${3:-100}"
D=staging.levelupgrowth.io
R="--resolve staging.levelupgrowth.io:443:127.0.0.1"
TOKEN=$(php /tmp/mint.php 2 2 2>/dev/null)
H="Authorization: Bearer $TOKEN"
TSV=/tmp/battery100.tsv

send_await(){
  local content="$1" body ACK ackid FIN
  body=$(python3 -c 'import json,sys;print(json.dumps({"content":sys.argv[1]}))' "$content")
  ACK=$(curl -s -g $R "https://$D/api/agents/sarah/messages" -H "$H" -H "Content-Type: application/json" -d "$body")
  ackid=$(echo "$ACK" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("ack_message_id","0"))' 2>/dev/null)
  [ -z "$ackid" ] && ackid=0
  for i in $(seq 1 32); do
    sleep 2.5
    FIN=$(curl -s -g $R "https://$D/api/agents/sarah/messages" -H "$H" | python3 -c "import sys,json;d=json.load(sys.stdin);f=[m for m in d if m['id']>$ackid and m['role']=='agent' and not m.get('is_ack')];print((f[-1]['content'] or '') if f else '')" 2>/dev/null)
    if [ -n "$FIN" ]; then echo "$FIN"; return; fi
  done
  echo "(no reply)"
}

while IFS=$'\t' read -r qid cat q; do
  [ -z "$qid" ] && continue
  if [ "$qid" -lt "$START" ] || [ "$qid" -gt "$END" ]; then continue; fi
  reply=$(send_await "$q")
  python3 -c 'import json,sys;print(json.dumps({"qid":int(sys.argv[1]),"cat":sys.argv[2],"q":sys.argv[3],"reply":sys.argv[4]}))' "$qid" "$cat" "$q" "$reply" >> "$OUT"
  echo "[$qid/$END $cat] done (${#reply} chars)"
done < "$TSV"
echo "############ DRIVE COMPLETE -> $OUT ############"
