"""
mcp_server.py — remote MCP tool surface for Stephen's Codex (MASTER-PLAN Phase 3).

Streamable-HTTP MCP server (FastMCP), bound to loopback 127.0.0.1:8765 and fronted
by Caddy at https://<domain>/mcp. A static bearer token (the app's API_KEY) gates
every request via ASGI middleware — Claude connects as a remote MCP connector
configured with that token. (OAuth per-client is a later upgrade.)

Run (under the venv python, by the codex-mcp systemd unit):
    API_KEY=... CODEX_BOOKS_DIR=/srv/codex/books python -m sync_engine.mcp_server
"""
from __future__ import annotations
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mcp.server.fastmcp import FastMCP
from mcp.server.streamable_http import TransportSecuritySettings
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.responses import JSONResponse

from api_client import CodexApi, ApiError
from mcp_tools import CodexTools


def build_app(token: str, books_root: str, api_url: str, engine_dir: str | None = None):
    """Build the Starlette ASGI app: FastMCP streamable-http + bearer gate."""
    api = CodexApi(api_url, token)
    tools = CodexTools(api, books_root, engine_dir)
    mcp = FastMCP("codex", host="127.0.0.1", port=8765, streamable_http_path="/mcp",
                  transport_security=TransportSecuritySettings(enable_dns_rebinding_protection=False))

    @mcp.tool()
    def codex_status() -> dict:
        """Health + counts: app name, books, entries, chapters."""
        return tools.status()

    @mcp.tool()
    def codex_search(query: str, book: str | None = None, limit: int = 25) -> list:
        """Search entries by name/slug/fields/sections text. Returns matches."""
        return tools.search(query, book, limit)

    @mcp.tool()
    def codex_get_entry(book: str, db: str, slug: str) -> str:
        """Return one entry rendered as Markdown (db: characters|locations|factions|objects|lore)."""
        return tools.get_entry(book, db, slug) or "(not found)"

    @mcp.tool()
    def codex_save_entry(book: str, db: str, slug: str, markdown: str) -> dict:
        """Save (create/update) an entry from Markdown via api.php push."""
        return tools.save_entry(book, db, slug, markdown)

    @mcp.tool()
    def codex_list_chapters(book: str | None = None) -> list:
        """List chapters (num, title, status, words, file)."""
        return tools.list_chapters(book)

    @mcp.tool()
    def codex_get_tasks(book: str | None = None, for_claude: int | None = None,
                        status: str | None = None) -> list:
        """List tasks, optionally filtered (for_claude=1, status=todo)."""
        return tools.get_tasks(book, for_claude, status)

    @mcp.tool()
    def codex_complete_task(task_id: int, result: str = "") -> dict:
        """Mark a task done with an optional result note."""
        return tools.complete_task(task_id, result)

    @mcp.tool()
    def codex_log_writing(book: str, words_added: int, total_words: int = 0,
                          chapters: str = "", minutes: int = 0, mood: str = "", note: str = "") -> dict:
        """Append a writing-log row for today."""
        return tools.log_writing(book, words_added, total_words, chapters, minutes, mood, note)

    @mcp.tool()
    def codex_sync(dry_run: bool = True) -> str:
        """Run one folder<->DB reconcile cycle. dry_run=True reports without writing."""
        return tools.sync(dry_run=dry_run, token=token, api_url=api_url)

    app = mcp.streamable_http_app()

    class BearerAuth(BaseHTTPMiddleware):
        # Accept the token either as an Authorization: Bearer header (smoke test /
        # SDK clients) OR as a ?k=<token> query param. The query form is needed
        # because Claude's "Add custom connector" UI takes only a URL (no header
        # field) and OAuth isn't implemented yet, so the secret rides in the URL.
        async def dispatch(self, request, call_next):
            auth = request.headers.get("authorization", "")
            supplied = auth[7:] if auth.startswith("Bearer ") else request.query_params.get("k", "")
            if supplied != token:
                return JSONResponse({"error": "unauthorized"}, status_code=401)
            return await call_next(request)

    app.add_middleware(BearerAuth)
    return app


def main() -> int:
    token = os.environ.get("API_KEY", "")
    if not token:
        print("ERROR: API_KEY not set."); return 2
    books_root = os.environ.get("CODEX_BOOKS_DIR", "/srv/codex/books")
    api_url = os.environ.get("CODEX_API_URL", "http://127.0.0.1:8081/api.php")
    app = build_app(token, books_root, api_url)
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8765)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
