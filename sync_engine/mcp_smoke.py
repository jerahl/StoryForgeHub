"""
mcp_smoke.py — end-to-end smoke test of the MCP tool surface (run ON the box).

Connects to the local MCP server over loopback with the bearer token, lists the
tools, then calls codex_status and codex_sync(dry_run=True). Proves the whole
path: MCP transport -> tools -> api.php -> DB.

    API_KEY=$(grep ^API_KEY= /etc/codex/codex.env | cut -d= -f2-) \
      /srv/codex/app/sync_engine/.venv/bin/python /srv/codex/app/sync_engine/mcp_smoke.py
"""
import asyncio
import os
import sys

from mcp.client.streamable_http import streamablehttp_client
from mcp import ClientSession

URL = os.environ.get("CODEX_MCP_URL", "http://127.0.0.1:8765/mcp")
TOKEN = os.environ.get("API_KEY", "")


def _show(result):
    if result.structuredContent:
        return result.structuredContent
    return [getattr(c, "text", repr(c)) for c in result.content]


async def main() -> int:
    headers = {"Authorization": f"Bearer {TOKEN}"}
    async with streamablehttp_client(URL, headers=headers) as (read, write, _):
        async with ClientSession(read, write) as session:
            await session.initialize()
            tools = await session.list_tools()
            print("tools:", ", ".join(t.name for t in tools.tools))
            print("\ncodex_status ->", _show(await session.call_tool("codex_status", {})))
            print("\ncodex_sync(dry_run=True) ->")
            r = await session.call_tool("codex_sync", {"dry_run": True})
            for c in r.content:
                if getattr(c, "text", None):
                    print(c.text)
    return 0


if __name__ == "__main__":
    if not TOKEN:
        print("ERROR: set API_KEY in the environment."); sys.exit(2)
    sys.exit(asyncio.run(main()))
