"""
mcp_auth.py — per-user token gate for the MCP server (standalone plan, Track B1).

The /mcp endpoint used to accept exactly one credential: the shared service
API_KEY, so every caller acted as the unscoped service identity. Now any token
api.php recognises gets through — the service key (constant-time compare, no
network) or a personal token from Account → API tokens, validated by asking
api.php itself (a `ping` with that token) and cached for a short TTL so a busy
session doesn't ping on every request. api.php remains the single authority on
which tokens exist; this gate never sees the token table.

Stdlib-only and transport-free so it unit-tests offline (the MCP SDK is not a
test dependency, matching the reconcile engine's stdlib-only rule).
"""
from __future__ import annotations
import hmac
import time
from typing import Callable, Optional

# Personal tokens are re-validated against api.php after this many seconds.
# Bounds how long a revoked token keeps working; small enough to be forgotten,
# large enough that one Claude conversation validates once, not per message.
VALIDATE_TTL = 60.0
_MAX_CACHE = 256   # runaway-guard; a real install has a handful of tokens


def extract_token(auth_header: str, query_k: str) -> str:
    """The credential a request carries: `Authorization: Bearer <t>` (SDK/smoke
    clients) or `?k=<t>` (Claude's connector UI takes only a URL)."""
    if auth_header.startswith("Bearer "):
        return auth_header[7:].strip()
    return (query_k or "").strip()


class TokenGate:
    """Decides whether a presented token may reach the MCP tools.

    `validate` is a callable(token) -> bool that asks api.php (a `ping` with
    the candidate as X-Codex-Token; 401 → False). Injected so tests need no
    network and the transport layer stays dumb.
    """

    def __init__(self, service_token: str, validate: Callable[[str], bool],
                 ttl: float = VALIDATE_TTL, clock: Callable[[], float] = time.monotonic):
        self.service_token = service_token or ""
        self.validate = validate
        self.ttl = ttl
        self.clock = clock
        self._ok: dict[str, float] = {}   # token -> expiry (validated user tokens)

    def is_service(self, token: str) -> bool:
        return self.service_token != "" and hmac.compare_digest(self.service_token, token)

    def check(self, token: str) -> bool:
        if not token:
            return False
        if self.is_service(token):
            return True
        now = self.clock()
        exp = self._ok.get(token)
        if exp is not None and exp > now:
            return True
        if self.validate(token):
            if len(self._ok) >= _MAX_CACHE:      # drop stale entries, then oldest
                self._ok = {t: e for t, e in self._ok.items() if e > now}
                while len(self._ok) >= _MAX_CACHE:
                    self._ok.pop(next(iter(self._ok)))
            self._ok[token] = now + self.ttl
            return True
        self._ok.pop(token, None)                 # revoked mid-TTL → drop the cache
        return False
