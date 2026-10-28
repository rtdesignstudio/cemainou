#!/bin/sh
# cemainou - conectează Cursor într-o singură comandă: serverul MCP (în ~/.cursor/mcp.json) și hook-urile care măsoară timpul
# (în ~/.cursor/hooks.json: trimiterea și sfârșitul fiecărui prompt, niciodată conținut). Restul setărilor Cursor rămân cum erau.
# Folosire, o dată, într-un terminal (comanda din profil: Agenți → Conectează un agent → Cursor):
#   curl -fsS <url>/agent/install-cursor.sh | sh -s -- <url> <cod>
# Codul (wsc_..., valabil 10 minute, o dată) se schimbă pe cheie la server; merge și o cheie dată direct ('3|wsa_...').
set -u
HELP='Nu merge? Problemele știute și soluțiile lor: https://rt.md/cemainou/ro/documentatie/#probleme'
trap 'c=$?; [ $c -ne 0 ] && [ $c -ne 3 ] && echo "$HELP" >&2' EXIT   # la orice eșec, unde se găsește ajutorul
URL=${1:-}
TOKEN=${2:-}
MODE=${3:-}                                                       # „client": doar serverul MCP, fără hook-uri și fără timp
if [ -z "$URL" ] || [ -z "$TOKEN" ]; then
  echo "folosire: install-cursor.sh <url> <cod>" >&2
  exit 2
fi
case "$TOKEN" in
  wsc_*|*\|*) ;;
  *) echo "Codul sau cheia par incomplete - copiază comanda întreagă din profil." >&2; exit 2 ;;
esac
URL=${URL%/}
case "$URL" in
  https://*|http://localhost*|http://127.0.0.1*) ;;
  *) echo "Adresa trebuie să fie https (sau localhost, pentru dezvoltare): $URL" >&2; exit 2 ;;
esac
HOME_DIR=${HOME:?}
command -v curl >/dev/null 2>&1 || { echo "Lipsește curl." >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "Lipsește python3 (pentru a completa ~/.cursor/mcp.json și hooks.json fără să stric ce e acolo)." >&2; exit 1; }

# 1. scriptul de hook, într-un folder temporar
tmp=$(mktemp -d 2>/dev/null || mktemp -d -t cemainou) || exit 1
trap 'c=$?; rm -rf "$tmp"; [ $c -ne 0 ] && [ $c -ne 3 ] && echo "$HELP" >&2' EXIT
curl -fsS "$URL/agent/heartbeat.sh" -o "$tmp/heartbeat.sh" || { echo "Nu am putut descărca $URL/agent/heartbeat.sh (serverul e pornit? adresa e bună?)." >&2; exit 1; }

# 2. codul se schimbă pe cheie (o singură dată)
case "$TOKEN" in
  wsc_*)
    TOKEN=$(curl -fsS --max-time 10 -X POST "$URL/api/connect" -H 'Accept: text/plain' --data-urlencode "code=$TOKEN" 2>/dev/null) || TOKEN=''
    case "$TOKEN" in
      *\|*) ;;
      *) echo "Codul de conectare a expirat sau a fost folosit deja. Creează altul din profil (Agenți → Conectează un agent)." >&2; exit 1 ;;
    esac ;;
esac

# 3. serverul MCP și hook-urile, completate în fișierele Cursor (o intrare cemainou veche se înlocuiește; restul rămâne)
mkdir -p "$HOME_DIR/.cursor" "$HOME_DIR/.config/cemainou" || { echo "Nu pot scrie în $HOME_DIR." >&2; exit 1; }
BEAT="$HOME_DIR/.config/cemainou/heartbeat.sh"
WSA_URL="$URL" WSA_TOKEN="$TOKEN" WSA_BEAT="$BEAT" WSA_MODE="$MODE" WSA_DIR="$HOME_DIR/.cursor" python3 - <<'PY' || { echo "Nu am putut scrie în ~/.cursor (mcp.json, hooks.json)." >&2; exit 1; }
import json, os, re
d = os.environ["WSA_DIR"]
def load(name, empty):
    try:
        with open(os.path.join(d, name)) as f:
            return json.load(f)
    except FileNotFoundError:
        return empty
mcp = load("mcp.json", {})
mcp.setdefault("mcpServers", {}).pop("whatsupai", None)   # numele vechi
mcp["mcpServers"]["cemainou"] = {"url": os.environ["WSA_URL"] + "/mcp", "headers": {"Authorization": "Bearer " + os.environ["WSA_TOKEN"], "X-Cemainou-Agent": "cursor"}}
hooks = load("hooks.json", {"version": 1, "hooks": {}})
hooks.setdefault("version", 1)
command = '"' + os.environ["WSA_BEAT"] + '"'
for event in ("beforeSubmitPrompt", "stop", "afterFileEdit"):
    entries = [e for e in hooks.setdefault("hooks", {}).get(event, []) if not re.search(r"(whatsupai|cemainou)/", str(e.get("command", "")))]
    hooks["hooks"][event] = entries if os.environ.get("WSA_MODE") == "client" else entries + [{"command": command}]
for name, data in (("mcp.json", mcp), ("hooks.json", hooks)):
    path = os.path.join(d, name)
    with open(path + ".tmp", "w") as f:
        json.dump(data, f, indent=2)
    os.chmod(path + ".tmp", 0o600)
    os.replace(path + ".tmp", path)
PY

if [ "$MODE" = client ]; then
  echo "Gata: Cursor e conectat la cemainou ($URL), cu contul tău de client (doar proiectele tale; timpul nu se măsoară). Repornește Cursor."
  exit 0
fi

# 4. scriptul de hook și config-ul cu adresa și cheia (600); filtrul pe foldere al omului rămâne
cp "$tmp/heartbeat.sh" "$BEAT" && chmod 700 "$BEAT" || { echo "Nu am putut scrie scriptul de hook." >&2; exit 1; }
umask 077
ONLY_LINE=$(cat "$HOME_DIR/.config/cemainou/config" "$HOME_DIR/.config/whatsupai/config" 2>/dev/null | grep -E '^(CEMAINOU|WHATSUPAI)_ONLY=' | head -1 | sed 's/^WHATSUPAI_/CEMAINOU_/')
{
  printf "CEMAINOU_URL='%s'\nCEMAINOU_TOKEN='%s'\n" "$URL" "$TOKEN"
  printf '# Doar sesiunile din aceste foldere ajung la cemainou (separate cu :). Fără rândul de mai jos, din orice folder.\n'
  if [ -n "$ONLY_LINE" ]; then printf '%s\n' "$ONLY_LINE"; else printf '# CEMAINOU_ONLY="$HOME/Clienti:$HOME/RT"\n'; fi
} > "$HOME_DIR/.config/cemainou/config" || { echo "Nu am putut scrie config-ul." >&2; exit 1; }

rm -rf "$HOME_DIR/.config/whatsupai"                          # configurarea de sub numele vechi, dacă era
echo "Gata: Cursor e conectat la cemainou ($URL), iar hook-urile îi măsoară timpul. Repornește Cursor; pentru un update spune-i „raportează în cemainou”."
