"""
api_client.py — thin client for the app's api.php (MASTER-PLAN Phase 2/3).

On the VPS the reconcile engine + MCP server talk to api.php over the LOCAL
loopback (http://127.0.0.1:8081/api.php), so api.php stays the single DB-writer
path. Stdlib only (urllib) so it runs with no deps.
"""
from __future__ import annotations
import json
import urllib.request
import urllib.parse
import urllib.error
from typing import Any, Dict, List, Optional


class ApiError(RuntimeError):
    pass


class CodexApi:
    def __init__(self, base_url: str, token: str, timeout: int = 30):
        self.base_url = base_url
        self.token = token
        self.timeout = timeout

    def _call(self, action: str, body: Optional[dict] = None, params: Optional[dict] = None) -> Any:
        qs = {"action": action}
        if params:
            qs.update({k: v for k, v in params.items() if v is not None})
        url = self.base_url + "?" + urllib.parse.urlencode(qs)
        data = None
        headers = {"X-Codex-Token": self.token, "Accept": "application/json"}
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=data, headers=headers,
                                     method="POST" if data is not None else "GET")
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                payload = json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            try:
                payload = json.loads(e.read().decode("utf-8"))
            except Exception:
                payload = {"error": f"HTTP {e.code}"}
            raise ApiError(f"{action}: {payload.get('error', e)}")
        except Exception as e:  # noqa: BLE001
            raise ApiError(f"{action}: {e}")
        if isinstance(payload, dict) and payload.get("error"):
            raise ApiError(f"{action}: {payload['error']}")
        return payload

    # --- read ---
    def ping(self) -> dict:
        return self._call("ping")

    def export(self) -> dict:
        return self._call("export")

    def get_tasks(self, book: Optional[str] = None, for_claude: Optional[int] = None,
                  status: Optional[str] = None) -> List[dict]:
        p = {"book": book, "for_claude": for_claude, "status": status}
        return (self._call("tasks", params=p) or {}).get("tasks", [])

    def get_writing_log(self, book: Optional[str] = None) -> List[dict]:
        return (self._call("writing-log", params={"book": book}) or {}).get("writing_log", [])

    # --- write (single DB-writer path) ---
    def push(self, books: list) -> dict:
        return self._call("push", body={"books": books})

    def apply(self, payload: dict) -> dict:
        return self._call("apply", body=payload)

    def import_snapshot(self, snapshot: dict) -> dict:
        return self._call("import", body=snapshot)
