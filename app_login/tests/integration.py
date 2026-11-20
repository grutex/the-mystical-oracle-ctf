"""Integration against a DISPOSABLE portal. Creates accounts and closes the event."""
import concurrent.futures
import hashlib
import hmac
import http.cookiejar
import json
import os
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from uuid import uuid4

BASE = os.environ["PORTAL_TEST_URL"].rstrip("/") + "/api/api.php"


class Client:
    def __init__(self):
        self.http = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ""
        self.call("bootstrap")

    def call(self, action, data=None, status=200, csrf=True, params=None):
        url = BASE + "?" + urllib.parse.urlencode({"action": action, **(params or {})})
        headers = {}
        body = None
        if data is not None:
            body = json.dumps(data).encode()
            headers = {"Content-Type": "application/json"}
            if csrf:
                headers["X-CSRF-Token"] = self.csrf
        request = urllib.request.Request(url, data=body, headers=headers)
        try:
            response = self.http.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        raw = response.read().decode()
        assert response.status == status, (action, response.status, status, raw)
        result = json.loads(raw)
        self.csrf = result.get("csrf", self.csrf)
        return result

    def login(self, email, password, status=200):
        return self.call("login", {"email": email, "password": password}, status=status)


def run():
    suffix = uuid4().hex[:10]
    username = "test_" + suffix
    email = username + "@example.test"
    password = "integration-only-password"
    secret = os.environ["PORTAL_TEST_ACCOUNT_SECRET"].encode()
    player, stranger, admin = Client(), Client(), Client()
    stranger.call("me", status=401)
    registration = {"username": username, "email": email,
                    "password": password, "acc_role": "admin"}
    player.call("register", registration, status=403, csrf=False)
    player.call("register", registration, status=201)
    player.call("register", registration, status=409)
    player.login(email, password, status=409)
    admin.login(os.environ["PORTAL_TEST_ADMIN_EMAIL"], os.environ["PORTAL_TEST_ADMIN_PASSWORD"])
    assert admin.call("start", {})["contest"]["started_at"]
    player.login(email, password)
    me = player.call("me")
    assert me["profile"]["role"] == "player"
    assert me["profile"]["username"] == username
    assert me["profile"]["score"] == 0
    assert len(me["challenges"]) == 6
    assert all("flag_hash" not in item for item in me["challenges"])
    flag = "ORACLE{sp1rit_" + hmac.new(
        secret, f"flag:{me['profile']['id']}:spirit".encode(), hashlib.sha256
    ).hexdigest()[:20] + "}"
    player.call("save_snapshot", {}, status=403)
    player.call("close", {}, status=403)
    player.call("schedule", {"ends_at": "2099-01-01T00:00:00Z"}, status=403)
    player.call("submit", {"challenge": "spirit", "flag": flag}, status=403, csrf=False)
    player.call("submit", {"challenge": "spirit", "flag": "FLAG{wrong}"}, status=400)
    assert not player.call("submit", {"challenge": "spirit", "flag": flag.upper()})["correct"]
    player.call("submit", {"challenge": "water", "flag": "ORACLE{pending}"}, status=409)
    player.call("submit", {"challenge": "light_dark", "flag": "ORACLE{pending}"}, status=409)
    # Independent PHP sessions targeting one account exercise database uniqueness.
    clients = [Client() for _ in range(6)]
    for client in clients:
        client.login(email, password)
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        replies = list(pool.map(lambda c: c.call("submit", {"challenge": "spirit", "flag": flag}), clients))
    assert sum(reply["points"] for reply in replies) == 100
    assert sum(reply["already_solved"] for reply in replies) == 5
    assert player.call("me")["profile"]["score"] == 100
    fire = "ORACLE{f1r3_" + hmac.new(secret, (username + ":fire").encode(), hashlib.sha256).hexdigest()[:16] + "}"
    assert player.call("submit", {"challenge": "fire", "flag": fire})["points"] == 100
    player.call("profile", {"alias": "Novo nome", "score": 99999, "role": "admin"})
    me = player.call("me")["profile"]
    assert me["score"] == 200 and me["role"] == "player" and me["login"] == username
    assert player.call("question", {})["profile"]["questionsCount"] == 1
    admin.login(os.environ["PORTAL_TEST_ADMIN_EMAIL"], os.environ["PORTAL_TEST_ADMIN_PASSWORD"])
    snapshot = admin.call("save_snapshot", {})["snapshot"]
    row = next(row for row in snapshot["rows"] if row["username"] == username)
    assert row["score"] == 200
    player.call("reset", {})
    assert player.call("me")["profile"]["score"] == 0
    saved = player.call("snapshot", params={"id": snapshot["id"]})["snapshot"]
    assert saved == snapshot, "Saved history changed after reset"
    player.call("submit", {"challenge": "spirit", "flag": flag})
    admin.call("schedule", {"ends_at": "2000-01-01T00:00:00Z"}, status=400)
    deadline = (datetime.now(timezone.utc) + timedelta(seconds=3)).replace(microsecond=0)
    admin.call("schedule", {"ends_at": deadline.strftime("%Y-%m-%dT%H:%M:%SZ")})
    time.sleep(max(0, deadline.timestamp() - time.time()) + .2)
    # First request after the deadline is a rejected mutation: closure must still commit.
    player.call("submit", {"challenge": "fire", "flag": fire}, status=409)
    board = player.call("leaderboard")
    assert board["contest"]["closed_at"].startswith(deadline.strftime("%Y-%m-%dT%H:%M:%S"))
    assert board["snapshot_id"]
    assert next(row for row in board["rows"] if row["username"] == username)["score"] == 100
    final = admin.call("close", {})["snapshot"]
    assert final["final"] and final["id"] == board["snapshot_id"]
    assert admin.call("save_snapshot", {})["snapshot"] == final
    player.call("reset", {}, status=409)
    player.call("profile", {"alias": "Depois do fim"}, status=409)
    player.call("logout", {})
    player.call("me", status=401)
    print("PASS: auth, CSRF, six challenges, pending flags, concurrent scoring, Fire HMAC, profile, snapshots, UTC deadline and closure.")


if __name__ == "__main__":
    run()
