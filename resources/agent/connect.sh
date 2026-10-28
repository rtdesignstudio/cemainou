#!/bin/sh
# cemainou - conectează tot calculatorul într-o singură comandă: caută ce agenți sunt instalați și îi leagă pe toți, cu aceeași cheie.
#   Claude Code (terminal, aplicația desktop Claude - fila Code, extensiile din editor): install.sh - serverul MCP, /upd, hook-urile pentru timp;
#   Cursor: install-cursor.sh - serverul MCP și hook-urile; Codex și Gemini CLI: serverul MCP, cu cheia din ~/.config/cemainou/token.env.
# Folosire, o dată, într-un terminal (sau lipită în chat, ca agentul s-o ruleze):
#   curl -fsS <url>/agent/connect.sh | sh -s -- <url> <cod>
# Codul (wsc_..., valabil 10 minute, o dată) se schimbă pe cheie o singură dată, aici; scripturile fiecărui agent primesc cheia.
set -u
HELP='Nu merge? Problemele știute și soluțiile lor: https://rt.md/cemainou/ro/documentatie/#probleme'
URL=${1:-}
CODE=${2:-}
MODE=${3:-}                                                       # „client": contul unui client, fără hook-uri și fără timp
if [ -z "$URL" ] || [ -z "$CODE" ]; then
  echo "folosire: connect.sh <url> <cod>" >&2
  exit 2
fi
URL=${URL%/}
case "$URL" in
  https://*|http://localhost*|http://127.0.0.1*) ;;
  *) echo "Adresa trebuie să fie https (sau localhost, pentru dezvoltare): $URL" >&2; exit 2 ;;
esac
command -v curl >/dev/null 2>&1 || { echo "Lipsește curl." >&2; exit 1; }
HOME_DIR=${HOME:?}

# 1. ce e instalat pe calculatorul acesta
for c in "$HOME_DIR/.local/bin" "$HOME_DIR/.claude/local" "$HOME_DIR/.npm-global/bin" "$HOME_DIR/.volta/bin" "$HOME_DIR/.bun/bin" /opt/homebrew/bin /usr/local/bin; do
  [ -d "$c" ] && case ":$PATH:" in *":$c:"*) ;; *) PATH="$PATH:$c" ;; esac
done
export PATH
has() { command -v "$1" >/dev/null 2>&1; }
CLAUDE=0; CURSOR=0; CODEX=0; GEMINI=0
{ has claude || [ -d "$HOME_DIR/.claude" ] || [ -d /Applications/Claude.app ] || [ -f "$HOME_DIR/.claude.json" ]; } && CLAUDE=1
{ has cursor || [ -d "$HOME_DIR/.cursor" ] || [ -d /Applications/Cursor.app ]; } && CURSOR=1
has codex && CODEX=1
has gemini && GEMINI=1
[ $CLAUDE$CURSOR$CODEX$GEMINI = 0000 ] && CLAUDE=1               # nimic găsit: Claude Code (scriptul lui spune ce lipsește)

# 2. codul devine cheie, o singură dată
case "$CODE" in
  wsc_*)
    TOKEN=$(curl -fsS --max-time 10 -X POST "$URL/api/connect" -H 'Accept: text/plain' --data-urlencode "code=$CODE" 2>/dev/null) || TOKEN=''
    case "$TOKEN" in
      *\|*) ;;
      *) echo "Codul de conectare a expirat sau a fost folosit deja. Creează altul din profil (Agenți și aplicații → Conectează)." >&2; echo "$HELP" >&2; exit 1 ;;
    esac ;;
  *\|*) TOKEN=$CODE ;;
  *) echo "Codul pare incomplet - copiază comanda întreagă din profil." >&2; exit 2 ;;
esac

# 3. fiecare agent găsit, cu aceeași cheie
DONE=''; FAILED=''
run() {                                                                   # $1 = numele, $2 = scriptul de pe server
  script=$(curl -fsS "$URL/agent/$2") || { FAILED="$FAILED, $1"; return; }
  printf '%s\n' "$script" | sh -s -- "$URL" "$TOKEN" "$MODE"
  case $? in
    0|3) DONE="$DONE, $1" ;;                                             # 3: conectat, dar fără hook-uri (scriptul a spus de ce)
    *) FAILED="$FAILED, $1" ;;
  esac
}
[ $CLAUDE = 1 ] && run 'Claude Code' install.sh
[ $CURSOR = 1 ] && run 'Cursor' install-cursor.sh
if [ $CODEX = 1 ] || [ $GEMINI = 1 ]; then                                # Codex și Gemini citesc cheia dintr-o variabilă de mediu
  ENVF="$HOME_DIR/.config/cemainou/token.env"
  (umask 077; mkdir -p "$HOME_DIR/.config/cemainou" && printf "export CEMAINOU_TOKEN='%s'\n" "$TOKEN" > "$ENVF")
  for rc in "$HOME_DIR/.zshrc" "$HOME_DIR/.bashrc"; do
    { [ -f "$rc" ] || [ "$rc" = "$HOME_DIR/.zshrc" ]; } && ! grep -qs 'cemainou/token.env' "$rc" && printf '\n# cemainou: cheia pentru Codex / Gemini CLI\n[ -f "$HOME/.config/cemainou/token.env" ] && . "$HOME/.config/cemainou/token.env"\n' >> "$rc"
  done
  CEMAINOU_TOKEN=$TOKEN; export CEMAINOU_TOKEN
  if [ $CODEX = 1 ]; then
    codex mcp remove cemainou >/dev/null 2>&1
    if codex mcp add cemainou --url "$URL/mcp" --bearer-token-env-var CEMAINOU_TOKEN >/dev/null 2>&1; then
      [ "$MODE" = client ] || { mkdir -p "$HOME_DIR/.codex/prompts" && curl -fsS "$URL/agent/upd.md" -o "$HOME_DIR/.codex/prompts/upd.md" 2>/dev/null; }
      DONE="$DONE, Codex"
    else FAILED="$FAILED, Codex"; fi
  fi
  if [ $GEMINI = 1 ]; then
    gemini mcp remove --scope user cemainou >/dev/null 2>&1
    gemini mcp add --scope user --transport http --header "Authorization: Bearer $TOKEN" cemainou "$URL/mcp" >/dev/null 2>&1 && DONE="$DONE, Gemini CLI" || FAILED="$FAILED, Gemini CLI"
  fi
fi

echo
if [ "$MODE" = client ]; then
  [ -n "$DONE" ] && echo "cemainou e conectat în: ${DONE#, }, cu contul tău de client (doar proiectele tale; timpul nu se măsoară). Deschide o sesiune nouă (sau repornește aplicația Claude)."
else
  [ -n "$DONE" ] && echo "cemainou e conectat în: ${DONE#, }. Deschide o sesiune nouă (sau repornește aplicația Claude): timpul se măsoară de la prima sesiune."
fi
[ -n "$FAILED" ] && { echo "Nu s-a putut conecta: ${FAILED#, } (vezi mesajele de mai sus)." >&2; echo "$HELP" >&2; }
[ -n "$DONE" ] || exit 1
