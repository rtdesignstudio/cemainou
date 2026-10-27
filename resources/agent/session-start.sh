#!/bin/sh
# cemainou - pornirea unei sesiuni Claude Code (hook SessionStart, sincron, la pornire, reluare, /clear și compactare).
# Anunță sesiunea și primește un singur rând pe care Claude Code îl pune în contextul sesiunii: id-ul sesiunii și proiectul ei,
# sau „nu e legată de un proiect, întreabă omul". Trimite doar: id-ul sesiunii, amprenta folderului (sha256 al căii, calculat aici;
# calea nu pleacă), remote-urile git (fără parole), proiectul dintr-un .cemainou (sau .whatsupai) și versiunea fișierelor cemainou de aici.
# Dacă serverul nu răspunde, nu scrie nimic. Dacă serverul are fișiere mai noi, le aduce în fundal (update.sh).
for CONF in "$HOME/.config/cemainou" "$HOME/.config/whatsupai"; do [ -r "$CONF/config" ] && break; done   # numele vechi (whatsupai), până îl mută update.sh
[ -r "$CONF/config" ] || exit 0
. "$CONF/config"
CEMAINOU_URL=${CEMAINOU_URL:-${WHATSUPAI_URL:-}}; CEMAINOU_TOKEN=${CEMAINOU_TOKEN:-${WHATSUPAI_TOKEN:-}}; CEMAINOU_ONLY=${CEMAINOU_ONLY:-${WHATSUPAI_ONLY:-}}
[ -n "${CEMAINOU_URL:-}" ] && [ -n "${CEMAINOU_TOKEN:-}" ] || exit 0

input=$(cat 2>/dev/null)
if command -v python3 >/dev/null 2>&1; then
  fields=$(printf '%s' "$input" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    d = {}
for k in ("session_id", "cwd"):
    print(str(d.get(k, "")).replace("\n", " "))
' 2>/dev/null)
else
  flat=$(printf '%s' "$input" | tr -d '\n')
  field() { printf '%s' "$flat" | grep -o "\"$1\" *: *\"[^\"]*\"" | head -1 | sed 's/^[^:]*: *"//; s/"$//; s#\\/#/#g'; }
  fields=$(printf '%s\n%s\n' "$(field session_id)" "$(field cwd)")
fi
session=$(printf '%s\n' "$fields" | sed -n 1p)
cwd=$(printf '%s\n' "$fields" | sed -n 2p)
[ -n "$session" ] || exit 0

# doar sesiunile din folderele alese, dacă omul le-a pus în config (CEMAINOU_ONLY="$HOME/Clienti:$HOME/RT"); fără el, din orice folder
if [ -n "${CEMAINOU_ONLY:-}" ]; then
  inside=''
  old_ifs=$IFS
  IFS=':'
  for dir in $CEMAINOU_ONLY; do
    [ -n "$dir" ] && case "$cwd/" in "${dir%/}/"*) inside=1 ;; esac
  done
  IFS=$old_ifs
  [ -n "$inside" ] || exit 0
fi

folder=''
remote=''
project=''
if [ -n "$cwd" ] && [ -d "$cwd" ]; then
  folder=$(printf '%s' "$cwd" | { shasum -a 256 2>/dev/null || sha256sum 2>/dev/null; } | cut -c1-64)
  [ ${#folder} -eq 64 ] || folder=''
  remote=$(git -C "$cwd" remote -v 2>/dev/null | sed -E 's#//[^/@]+@#//#g' | tr '\n\t' '  ' | cut -c1-1900)   # fără user:parolă din adrese
  d="$cwd"
  while [ -n "$d" ] && [ "$d" != "/" ]; do
    for m in .cemainou .whatsupai; do                    # fișierul cu proiectul; .whatsupai, numele vechi, merge în continuare
      [ -f "$d/$m" ] && { project=$(sed -n 's/^project: *//p' "$d/$m" | head -1 | tr -d '\r'); break 2; }
    done
    d=$(dirname "$d")
  done
fi

mine=$(cat "$CONF/version" 2>/dev/null | tr -cd '0-9a-f')
headers=$(mktemp 2>/dev/null) || headers=''
line=$(curl -fsS --max-time 3 -X POST "$CEMAINOU_URL/api/session" ${headers:+-D "$headers"} \
    -H "Authorization: Bearer $CEMAINOU_TOKEN" -H "Accept: text/plain" \
    --data-urlencode "session=$session" --data-urlencode "folder=$folder" --data-urlencode "remote=$remote" \
    --data-urlencode "project=$project" --data-urlencode "agent=claude-code" --data-urlencode "version=$mine" 2>/dev/null) || { [ -n "$headers" ] && rm -f "$headers"; exit 0; }
[ -n "$line" ] && printf '%s\n' "$line"

# comenzile și hook-urile la zi: dacă serverul are altă versiune, update.sh rulează în fundal (sesiunea nu așteaptă; la următoarea sunt noi)
if [ -n "$headers" ]; then
  latest=$(tr -d '\r' < "$headers" | sed -n 's/^[Xx]-[A-Za-z]*-[Aa]gent-[Vv]ersion: *//p' | tr -cd '0-9a-f' | head -c 12)
  rm -f "$headers"
  if [ -n "$latest" ] && [ "$latest" != "$mine" ]; then
    ( curl -fsS --max-time 20 "$CEMAINOU_URL/agent/update.sh" 2>/dev/null | sh -s -- "$CEMAINOU_URL" quiet >/dev/null 2>&1 & ) >/dev/null 2>&1
  fi
fi
exit 0
