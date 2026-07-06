"""Offline tests for the per-user MCP token gate (Track B1). No network, no MCP SDK."""
import os, sys, unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from mcp_auth import TokenGate, extract_token  # noqa: E402


class Extract(unittest.TestCase):
    def test_bearer_header_wins(self):
        self.assertEqual(extract_token("Bearer abc ", "zzz"), "abc")

    def test_query_param_fallback(self):
        self.assertEqual(extract_token("", " qk "), "qk")
        self.assertEqual(extract_token("Basic zzz", "qk"), "qk")

    def test_nothing(self):
        self.assertEqual(extract_token("", ""), "")


class FakeClock:
    def __init__(self): self.t = 1000.0
    def __call__(self): return self.t


class Gate(unittest.TestCase):
    def setUp(self):
        self.clock = FakeClock()
        self.valid = {"codex_alice"}
        self.calls = []
        def validate(tok):
            self.calls.append(tok)
            return tok in self.valid
        self.gate = TokenGate("service-key", validate, ttl=60.0, clock=self.clock)

    def test_service_token_no_network(self):
        self.assertTrue(self.gate.check("service-key"))
        self.assertEqual(self.calls, [])

    def test_empty_rejected(self):
        self.assertFalse(self.gate.check(""))
        self.assertEqual(self.calls, [])

    def test_empty_service_key_never_matches_empty(self):
        g = TokenGate("", lambda t: False)
        self.assertFalse(g.is_service(""))
        self.assertFalse(g.check(""))

    def test_user_token_validated_then_cached(self):
        self.assertTrue(self.gate.check("codex_alice"))
        self.assertTrue(self.gate.check("codex_alice"))
        self.assertEqual(self.calls, ["codex_alice"])   # second hit came from cache

    def test_cache_expires_and_revalidates(self):
        self.gate.check("codex_alice")
        self.clock.t += 61
        self.assertTrue(self.gate.check("codex_alice"))
        self.assertEqual(self.calls, ["codex_alice", "codex_alice"])

    def test_bad_token_rejected_not_cached(self):
        self.assertFalse(self.gate.check("codex_mallory"))
        self.assertFalse(self.gate.check("codex_mallory"))
        self.assertEqual(self.calls, ["codex_mallory", "codex_mallory"])  # never cached

    def test_revoked_token_dies_at_ttl(self):
        self.gate.check("codex_alice")
        self.valid.clear()                               # revoked in the app
        self.assertTrue(self.gate.check("codex_alice"))  # still inside the TTL window
        self.clock.t += 61
        self.assertFalse(self.gate.check("codex_alice"))
        self.assertFalse(self.gate.check("codex_alice"))  # and the cache entry is gone

    def test_cache_cap_evicts(self):
        import mcp_auth
        for i in range(mcp_auth._MAX_CACHE + 5):
            tok = f"codex_u{i}"
            self.valid.add(tok)
            self.assertTrue(self.gate.check(tok))
        self.assertLessEqual(len(self.gate._ok), mcp_auth._MAX_CACHE)


if __name__ == "__main__":
    unittest.main(verbosity=2)
