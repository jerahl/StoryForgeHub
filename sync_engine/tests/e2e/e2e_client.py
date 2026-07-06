"""E2E: stateless MCP server + php -S api.php. Proves the per-user token rides
the contextvar into api.php and gets user-level scoping; the service token
stays unscoped; bad tokens get 401 at the transport; and the full OAuth 2.1
dance (register -> sign-in -> consent -> code -> tokens -> MCP access) works
end to end (Track B3)."""
import asyncio, base64, hashlib, json, os, re, secrets, sys
from urllib.parse import urlencode, urlparse, parse_qs

import httpx
from mcp import ClientSession
from mcp.client.streamable_http import streamablehttp_client

URL = "http://127.0.0.1:8765/mcp"
APP = "http://127.0.0.1:8081"
SERVICE = os.environ["SERVICE_KEY"]
ALICE = os.environ["ALICE_TOKEN"]

PASS = 0; FAIL = 0
def check(label, cond):
    global PASS, FAIL
    cond = bool(cond)
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

    # ---- OAuth 2.1 dance (Track B3) ----
    r = httpx.post(URL, json={})
    check("401 advertises the OAuth resource metadata",
          "oauth-protected-resource" in r.headers.get("www-authenticate", ""))
    meta = httpx.get(f"{APP}/oauth.php?action=as_metadata").json()
    check("AS metadata serves the endpoints",
          meta["code_challenge_methods_supported"] == ["S256"] and "token_endpoint" in meta)
    rs = httpx.get(f"{APP}/oauth.php?action=resource_metadata").json()
    check("resource metadata points at /mcp", rs["resource"].endswith("/mcp"))

    cb = "https://claude.ai/api/mcp/auth_callback"
    reg = httpx.post(meta["registration_endpoint"],
                     json={"client_name": "E2E Claude", "redirect_uris": [cb]})
    check("dynamic registration returns a public client",
          reg.status_code == 201 and reg.json()["token_endpoint_auth_method"] == "none")
    client_id = reg.json()["client_id"]

    verifier = base64.urlsafe_b64encode(secrets.token_bytes(48)).rstrip(b"=").decode()
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
    auth_q = {"response_type": "code", "client_id": client_id, "redirect_uri": cb,
              "state": "xyz123", "code_challenge": challenge, "code_challenge_method": "S256"}

    with httpx.Client(base_url=APP, follow_redirects=False) as web:
        # signed out: the authorize URL shows a sign-in form, not consent
        page = web.get("/oauth.php?" + urlencode(auth_q))
        check("authorize asks for sign-in first", "auth_login" in page.text and "oauth_decision" not in page.text)
        # sign in (the normal app login) with next= back to the authorize URL
        web.post("/index.php", data={"action": "auth_login", "email": "alice@example.com",
                                     "password": "password1", "next": "?"})
        page = web.get("/oauth.php?" + urlencode(auth_q))
        m = re.search(r'name="nonce" value="([^"]+)"', page.text)
        check("signed-in authorize shows the consent form", "oauth_decision" in page.text and m)
        # deny first: must bounce back with error, no code
        deny = web.post("/oauth.php?" + urlencode(auth_q),
                        data={"action": "oauth_decision", "decision": "deny", "nonce": m.group(1),
                              **auth_q, "scope": "codex"})
        check("deny redirects with access_denied", "error=access_denied" in deny.headers.get("location", ""))
        # approve (fresh nonce)
        page = web.get("/oauth.php?" + urlencode(auth_q))
        m = re.search(r'name="nonce" value="([^"]+)"', page.text)
        approve = web.post("/oauth.php?" + urlencode(auth_q),
                           data={"action": "oauth_decision", "decision": "approve", "nonce": m.group(1),
                                 **auth_q, "scope": "codex"})
        loc = approve.headers.get("location", "")
        q = parse_qs(urlparse(loc).query)
        check("approve redirects back with code + state",
              loc.startswith(cb) and "code" in q and q.get("state") == ["xyz123"])
        code = q["code"][0]

    tok = httpx.post(meta["token_endpoint"], data={
        "grant_type": "authorization_code", "code": code, "client_id": client_id,
        "redirect_uri": cb, "code_verifier": verifier}).json()
    check("token endpoint issues bearer + refresh",
          tok.get("token_type") == "Bearer" and tok.get("refresh_token", "").startswith("codexor_"))

    async def as_oauth(s):
        st = json.loads(text_of(await s.call_tool("codex_status", {})))
        check("OAuth access token acts as alice on the MCP", st["books"] == 1)
    await with_token(tok["access_token"], as_oauth)

    tok2 = httpx.post(meta["token_endpoint"], data={
        "grant_type": "refresh_token", "refresh_token": tok["refresh_token"],
        "client_id": client_id}).json()
    check("refresh rotates the tokens", tok2.get("access_token") not in ("", tok["access_token"]))
    dead = httpx.get(f"{APP}/api.php?action=ping", headers={"X-Codex-Token": tok["access_token"]})
    check("rotated-out access token is dead at api.php", dead.status_code == 401)
    replay = httpx.post(meta["token_endpoint"], data={
        "grant_type": "refresh_token", "refresh_token": tok["refresh_token"],
        "client_id": client_id})
    check("replaying the old refresh token is refused", replay.status_code == 400)
    dead2 = httpx.get(f"{APP}/api.php?action=ping", headers={"X-Codex-Token": tok2["access_token"]})
    check("…and the replay revoked the whole grant", dead2.status_code == 401)

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
