"""E2E: stateless MCP server + php -S api.php. Proves the per-user token rides
the contextvar into api.php and gets user-level scoping; the service token
stays unscoped; bad tokens get 401 at the transport."""
import asyncio, json, os, sys

import httpx
from mcp import ClientSession
from mcp.client.streamable_http import streamablehttp_client

URL = "http://127.0.0.1:8765/mcp"
SERVICE = os.environ["SERVICE_KEY"]
ALICE = os.environ["ALICE_TOKEN"]

PASS = 0; FAIL = 0
def check(label, cond):
    global PASS, FAIL
    print(("  ok  " if cond else "FAIL  ") + label)
    PASS, FAIL = PASS + cond, FAIL + (not cond)

def text_of(res):
    return "\n".join(getattr(c, "text", "") for c in res.content)

async def with_token(token, fn):
    async with streamablehttp_client(URL, headers={"Authorization": f"Bearer {token}"}) as (r, w, _):
        async with ClientSession(r, w) as s:
            await s.initialize()
            await fn(s)

async def main():
    # transport-level auth
    resp = httpx.post(URL, json={})
    check("no token -> 401", resp.status_code == 401)
    resp = httpx.post(URL + "?k=wrong", json={})
    check("bad token -> 401", resp.status_code == 401)

    async def as_service(s):
        st = json.loads(text_of(await s.call_tool("codex_status", {})))
        check("service sees both books", st["books"] == 2)
        r = await s.call_tool("codex_get_chapter", {"book": "alien", "file": "a01.md"})
        check("service reads the other book's chapter", "First contact" in text_of(r))

    async def as_alice(s):
        st = json.loads(text_of(await s.call_tool("codex_status", {})))
        check("alice sees only her book", st["books"] == 1)
        r = await s.call_tool("codex_get_chapter", {"book": "echo", "file": "ch01.md"})
        check("alice reads her chapter body", "Snow fell" in text_of(r))
        r = await s.call_tool("codex_get_chapter", {"book": "alien", "file": "a01.md"})
        check("alice is refused on the other book", "forbidden" in text_of(r).lower())
        r = await s.call_tool("codex_search", {"query": "watchtower"})
        t = text_of(r)
        check("search returns snippet hits", "snippet" in t and "watchtower" in t)
        r = await s.call_tool("codex_create_task", {"book": "echo", "title": "e2e task", "for_claude": True})
        check("alice creates a task on her book", '"id"' in text_of(r))
        r = await s.call_tool("codex_update_task", {"task_id": 1, "status": "done", "result": "done by e2e"})
        check("alice updates the task", '"done by e2e"' in text_of(r))
        r = await s.call_tool("codex_create_task", {"book": "alien", "title": "sneaky"})
        check("task on a foreign book is refused", "unknown book" in text_of(r).lower() or "forbidden" in text_of(r).lower())
        r = await s.call_tool("codex_sync", {"dry_run": True})
        check("codex_sync refuses a personal token", "refused" in text_of(r))
        r = await s.call_tool("codex_list_entries", {"book": "echo"})
        check("entry list includes aria", "aria" in text_of(r))

    await with_token(SERVICE, as_service)
    await with_token(ALICE, as_alice)

    # interleaved sessions: two identities alive at once, no cross-bleed
    async with streamablehttp_client(URL, headers={"Authorization": f"Bearer {SERVICE}"}) as (r1, w1, _):
        async with ClientSession(r1, w1) as s1:
            await s1.initialize()
            async with streamablehttp_client(URL, headers={"Authorization": f"Bearer {ALICE}"}) as (r2, w2, _):
                async with ClientSession(r2, w2) as s2:
                    await s2.initialize()
                    a = json.loads(text_of(await s1.call_tool("codex_status", {})))
                    b = json.loads(text_of(await s2.call_tool("codex_status", {})))
                    c = json.loads(text_of(await s1.call_tool("codex_status", {})))
                    check("interleaved sessions keep their identities", a["books"] == 2 and b["books"] == 1 and c["books"] == 2)

    print(f"\n{PASS} passed, {FAIL} failed")
    return 1 if FAIL else 0

if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
