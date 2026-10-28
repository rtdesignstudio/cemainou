#!/bin/sh
# cemainou - bătaia de inimă a agentului, chemată de hook-urile Claude Code (UserPromptSubmit, PostToolUse, Stop, SessionEnd; pornirea e session-start.sh)
# și de cele Cursor (beforeSubmitPrompt, stop, afterFileEdit: aceleași margini ale promptului; sesiunea = conversation_id, folderul = primul workspace).
# Trimite doar: evenimentul, id-ul sesiunii, amprenta folderului (sha256 al căii; calea nu pleacă), remote-urile git (fără parole) și proiectul dintr-un .cemainou (sau .whatsupai).
# Un prompt pe care nu l-a scris omul (Claude Code pornește singur o tură când se termină o sarcină din fundal: <task-notification>, chiar
# cu laptopul închis) pleacă drept semnal de unealtă, nu drept prompt: agentul a lucrat, dar omul nu era acolo.
# Niciodată prompturi, cod sau căi de fișiere. Trimiterea și sfârșitul fiecărui prompt pleacă mereu; uneltele, cel mult o dată pe minut.
# Cererea pleacă în fundal, iar scriptul nu scrie nimic pe ecran (Claude Code ar lua textul drept context), cu o excepție: rândul pentru update-ul
# de azi, cerut pe loc cel mult o dată la 30 de minute (vezi jos). Lui Cursor îi răspunde „continuă" la prompt.
input=$(cat 2>/dev/null)
if command -v python3 >/dev/null 2>&1; then
  fields=$(printf '%s' "$input" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    d = {}
raw = str(d.get("hook_event_name", ""))
cursor = {"beforeSubmitPrompt": "UserPromptSubmit", "stop": "Stop", "afterFileEdit": "PostToolUse"}
roots = d.get("workspace_roots") or [""]
event = cursor.get(raw, raw)
if event == "UserPromptSubmit" and str(d.get("prompt", "")).lstrip().startswith("<task-notification"):
    event = "PostToolUse"
for value in (event, d.get("session_id") or d.get("conversation_id") or "", d.get("cwd") or roots[0], "cursor" if raw in cursor else "claude-code", raw):
    print(str(value).replace("\n", " "))
' 2>/dev/null)
else
  flat=$(printf '%s' "$input" | tr -d '\n')
  field() { printf '%s' "$flat" | grep -o "\"$1\" *: *\"[^\"]*\"" | head -1 | sed 's/^[^:]*: *"//; s/"$//; s#\\/#/#g'; }
  raw=$(field hook_event_name)
  case "$raw" in
    beforeSubmitPrompt) ev=UserPromptSubmit; ag=cursor ;; stop) ev=Stop; ag=cursor ;; afterFileEdit) ev=PostToolUse; ag=cursor ;; *) ev=$raw; ag=claude-code ;;
  esac
  [ "$ev" = UserPromptSubmit ] && printf '%s' "$flat" | grep -q '"prompt" *: *" *<task-notification' && ev=PostToolUse
  fields=$(printf '%s\n%s\n%s\n%s\n%s\n' "$ev" "$(field session_id)$(field conversation_id)" "$(field cwd)" "$ag" "$raw")
fi
event=$(printf '%s\n' "$fields" | sed -n 1p)
session=$(printf '%s\n' "$fields" | sed -n 2p)
cwd=$(printf '%s\n' "$fields" | sed -n 3p)
agent=$(printf '%s\n' "$fields" | sed -n 4p)
raw=$(printf '%s\n' "$fields" | sed -n 5p)
# Cursor așteaptă un răspuns la trimiterea promptului: îl lăsăm mereu să meargă, orice ar fi (și când nu trimitem nimic)
[ "$raw" = beforeSubmitPrompt ] && trap 'printf "{\"continue\":true}\n"' EXIT

for CONF in "$HOME/.config/cemainou" "$HOME/.config/whatsupai"; do [ -r "$CONF/config" ] && break; done   # numele vechi (whatsupai), până îl mută update.sh
[ -r "$CONF/config" ] || exit 0
. "$CONF/config"
CEMAINOU_URL=${CEMAINOU_URL:-${WHATSUPAI_URL:-}}; CEMAINOU_TOKEN=${CEMAINOU_TOKEN:-${WHATSUPAI_TOKEN:-}}; CEMAINOU_ONLY=${CEMAINOU_ONLY:-${WHATSUPAI_ONLY:-}}
[ -n "${CEMAINOU_URL:-}" ] && [ -n "${CEMAINOU_TOKEN:-}" ] || exit 0
[ -n "$event" ] || exit 0

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

stamps="$HOME/.cache/cemainou"
(umask 077; mkdir -p "$stamps") 2>/dev/null
stamp="$stamps/${session:-nosession}"
case "$event" in
  UserPromptSubmit|Stop|SessionEnd) ;;                  # marginile fiecărui prompt: mereu (din ele iese durata lui)
  *) [ -f "$stamp" ] && [ -n "$(find "$stamp" -mmin -1 2>/dev/null)" ] && exit 0 ;;
esac
touch "$stamp" 2>/dev/null

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

# la un prompt în Claude Code, cel mult o dată la 30 de minute pe sesiune: cererea pleacă pe loc (2 secunde cel mult), iar dacă serverul
# răspunde cu un rând (n-ai raportat azi, după o oră de lucru), îl scrie: Claude Code îl ia drept context și propune singur update-ul
nudge="$stamps/${session:-nosession}.nudge"
if [ "$event" = UserPromptSubmit ] && [ "${agent:-claude-code}" = claude-code ] && ! { [ -f "$nudge" ] && [ -n "$(find "$nudge" -mmin -30 2>/dev/null)" ]; }; then
  touch "$nudge" 2>/dev/null
  line=$(curl -fsS --max-time 2 -X POST "$CEMAINOU_URL/api/heartbeat" \
    -H "Authorization: Bearer $CEMAINOU_TOKEN" -H "Accept: text/plain" \
    --data-urlencode "event=$event" --data-urlencode "session=$session" --data-urlencode "folder=$folder" --data-urlencode "remote=$remote" \
    --data-urlencode "project=$project" --data-urlencode "agent=claude-code" --data-urlencode "nudge=1" 2>/dev/null)
  [ -n "$line" ] && printf '%s\n' "$line"
  exit 0
fi

( curl -fsS --max-time 3 -o /dev/null -X POST "$CEMAINOU_URL/api/heartbeat" \
    -H "Authorization: Bearer $CEMAINOU_TOKEN" -H "Accept: application/json" \
    --data-urlencode "event=$event" --data-urlencode "session=$session" --data-urlencode "folder=$folder" --data-urlencode "remote=$remote" \
    --data-urlencode "project=$project" --data-urlencode "agent=${agent:-claude-code}" >/dev/null 2>&1 & )
exit 0
