"""
mcp_server.py — remote MCP tool surface for Stephen's Codex.

Streamable-HTTP MCP server (FastMCP), bound to loopback 127.0.0.1:8765 and
fronted by Caddy at https://<domain>/mcp.

Auth (standalone plan, Track B1 — per-user): every request must carry a token
(`Authorization: Bearer` or `?k=` for Claude's URL-only connector UI). The
shared service API_KEY still works and stays unscoped (admin automation); any
other value is validated against api.php as a personal token from
Account → API tokens, and — the point — is passed through as the X-Codex-Token
on every api.php call, so the request acts as that user: same book membership
scoping, same role checks, same activity-log attribution as the web UI.

The server runs stateless (no MCP session affinity), so each tool call executes
inside the HTTP request that carried it and the caller's token rides a
contextvar from the auth middleware into the api client.

Run (under the venv python, by the codex-mcp systemd unit):
    API_KEY=... python -m sync_engine.mcp_server
"""
from __future__ import annotations
import contextvars
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mcp.server.fastmcp import FastMCP
from mcp.server.streamable_http import TransportSecuritySettings
from starlette.requests import Request
from starlette.responses import JSONResponse

from api_client import CodexApi, ApiError
from mcp_auth import TokenGate, extract_token
from mcp_tools import CodexTools

# The authenticated caller's token for the request being served. Set by the
# auth middleware, read by the CodexApi token provider on every api.php call.
CURRENT_TOKEN: contextvars.ContextVar[str] = contextvars.ContextVar("codex_token", default="")


class TokenAuthMiddleware:
    """Pure ASGI (not BaseHTTPMiddleware): the downstream app runs in this same
    context, so the contextvar set here is visible to the tool call it guards.

    When `public_url` is set, 401s carry the RFC 9728 discovery pointer so an
    OAuth-capable client (Claude's connector UI, Track B3) can find the
    authorization server and sign the user in instead of failing."""

    def __init__(self, app, gate: TokenGate, public_url: str = ""):
        self.app = app
        self.gate = gate
        self.public_url = public_url.rstrip("/")

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            return await self.app(scope, receive, send)
        request = Request(scope)
        token = extract_token(request.headers.get("authorization", ""),
                              request.query_params.get("k", ""))
        if not self.gate.check(token):
            headers = {}
            if self.public_url:
                headers["WWW-Authenticate"] = (
                    'Bearer resource_metadata='
                    f'"{self.public_url}/.well-known/oauth-protected-resource"')
            return await JSONResponse({"error": "unauthorized"}, status_code=401,
                                      headers=headers)(scope, receive, send)
        ctx = CURRENT_TOKEN.set(token)
        try:
            await self.app(scope, receive, send)
        finally:
            CURRENT_TOKEN.reset(ctx)


def build_app(service_token: str, api_url: str, public_url: str = ""):
    """Build the Starlette ASGI app: stateless FastMCP + per-user token gate.
    `public_url` (e.g. https://<domain>) enables the OAuth discovery pointer on 401s."""
    api = CodexApi(api_url, lambda: CURRENT_TOKEN.get())
    tools = CodexTools(api)

    def validate(candidate: str) -> bool:
        try:
            CodexApi(api_url, candidate).ping()
            return True
        except ApiError:
            return False

    gate = TokenGate(service_token, validate)
    mcp = FastMCP("codex", host="127.0.0.1", port=8765, streamable_http_path="/mcp",
                  stateless_http=True,
                  transport_security=TransportSecuritySettings(enable_dns_rebinding_protection=False))

    @mcp.tool()
    def codex_status() -> dict:
        """Health + counts: app name, and the books/entries/chapters you can see."""
        return tools.status()

    @mcp.tool()
    def codex_search(query: str, book: str | None = None, limit: int = 25) -> list:
        """Search entries, chapters, and notes (server-side, with snippets)."""
        return tools.search(query, book, limit)

    @mcp.tool()
    def codex_get_entry(book: str, db: str, slug: str) -> str:
        """Return one entry rendered as Markdown (db: characters|locations|factions|objects|lore)."""
        return tools.get_entry(book, db, slug) or "(not found)"

    @mcp.tool()
    def codex_list_entries(book: str, db: str | None = None) -> list:
        """List a book's entries (db, slug, name, status, type) without bodies."""
        return tools.list_entries(book, db)

    @mcp.tool()
    def codex_save_entry(book: str, db: str, slug: str, markdown: str) -> dict:
        """Save (create/update) an entry from Markdown."""
        return tools.save_entry(book, db, slug, markdown)

    @mcp.tool()
    def codex_get_chapter(book: str, chapter_id: int | None = None,
                          file: str | None = None) -> dict:
        """Read one manuscript chapter INCLUDING its Markdown body, by id or by
        filename (e.g. 'ch01.md'). Also returns num/title/status/words."""
        return tools.get_chapter(book, chapter_id, file)

    @mcp.tool()
    def codex_save_chapter(book: str, filename: str, markdown: str,
                           base_hash: str = "") -> dict:
        """Create/update a manuscript chapter. filename is a bare name (e.g.
        'ch-05-the-wall.md'). Creating a new chapter needs no base_hash; to
        UPDATE an existing one you must pass the body_hash you got from
        codex_get_chapter — if the chapter moved meanwhile the save is refused
        and the result carries the current hash + body so you can merge and
        retry. Never save over prose you haven't read."""
        return tools.save_chapter(book, filename, markdown, base_hash)

    @mcp.tool()
    def codex_create_chapter(book: str, title: str, num: str = "") -> dict:
        """Create a new, empty titled chapter (ch-NN-title.md seeded with its
        heading). num defaults to one past the highest existing chapter."""
        return tools.create_chapter(book, title, num)

    @mcp.tool()
    def codex_push_files(book: str, files: dict, reconcile_chapters: bool = False) -> dict:
        """Push new/updated files (a map of relpath -> Markdown) to a book.
        Handles chapters (Manuscript/<file>.md), entries (Codex/<Folder>/<slug>.md),
        notes (Codex/Notes/<slug>.md), meta (Codex/Meta/<slug>.md), and sources
        (Codex/Sources/<key>.md). reconcile_chapters=True lets a manuscript push
        archive chapters it omits; default False preserves existing chapters."""
        return tools.push_files(book, files, reconcile_chapters)

    @mcp.tool()
    def codex_list_chapters(book: str | None = None) -> list:
        """List chapters (num, title, status, words, file)."""
        return tools.list_chapters(book)

    @mcp.tool()
    def codex_get_diagnostics(book: str, chapter_id: int) -> dict:
        """Prose diagnostics for a chapter — overused words, repeated phrases,
        patterns to review, dialogue tags — the app's Smart-editing data."""
        return tools.get_diagnostics(book, chapter_id)

    @mcp.tool()
    def codex_get_tasks(book: str | None = None, for_claude: int | None = None,
                        status: str | None = None) -> list:
        """List tasks, optionally filtered (for_claude=1, status=todo)."""
        return tools.get_tasks(book, for_claude, status)

    @mcp.tool()
    def codex_create_task(book: str, title: str, body: str = "",
                          for_claude: bool = False, priority: str = "med") -> dict:
        """Create a task on a book's Tasks page (priority: low|med|high).
        for_claude=True flags it for a future Claude session to pick up."""
        return tools.create_task(book, title, body, for_claude, priority)

    @mcp.tool()
    def codex_update_task(task_id: int, status: str | None = None,
                          result: str | None = None, title: str | None = None,
                          body: str | None = None) -> dict:
        """Update a task's status (todo|doing|done), result note, title, or body."""
        return tools.update_task(task_id, status, result, title, body)

    @mcp.tool()
    def codex_complete_task(task_id: int, result: str = "") -> dict:
        """Mark a task done with an optional result note."""
        return tools.complete_task(task_id, result)

    @mcp.tool()
    def codex_log_writing(book: str, words_added: int, total_words: int = 0,
                          chapters: str = "", minutes: int = 0, mood: str = "", note: str = "") -> dict:
        """Append a writing-log row for today."""
        return tools.log_writing(book, words_added, total_words, chapters, minutes, mood, note)

    app = mcp.streamable_http_app()
    return TokenAuthMiddleware(app, gate, public_url)


def main() -> int:
    token = os.environ.get("API_KEY", "")
    if not token:
        print("ERROR: API_KEY not set."); return 2
    api_url = os.environ.get("CODEX_API_URL", "http://127.0.0.1:8081/api.php")
    public_url = os.environ.get("CODEX_PUBLIC_URL", "")
    app = build_app(token, api_url, public_url=public_url)
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8765)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
