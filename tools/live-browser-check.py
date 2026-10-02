#!/usr/bin/env python3
"""
Glaust MS — real headless-Chrome check of a running site (live or staging).

HTTP checks (deploy/check.sh) prove the server answers; this proves what the BROWSER
does with it: console errors and CSP violations, Alpine booting, the CBAR ticker and
"my day" panel loading, charts actually drawn, Ctrl+K palette, dark theme, horizontal
overflow at desktop and phone width. Screenshots go to --shots for a visual review.

Usage:  python tools/live-browser-check.py <base-url> <email> <password> [--shots DIR]
Needs:  pip install websocket-client ; Google Chrome
"""
from __future__ import annotations

import base64
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request

import websocket

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

args = [a for a in sys.argv[1:] if not a.startswith("--")]
BASE = (args[0] if args else "http://127.0.0.1:8000").rstrip("/")
EMAIL = args[1] if len(args) > 1 else ""
PASSWORD = args[2] if len(args) > 2 else ""
SHOTS = sys.argv[sys.argv.index("--shots") + 1] if "--shots" in sys.argv else ""

PAGES = [
    ("İdarə paneli", "/"),
    ("Mənim işlərim", "/my-work"),
    ("Valyuta", "/currency"),
    ("Layihələr", "/projects"),
    ("Tapşırıqlar (Kanban)", "/tasks"),
    ("Tapşırıqlar (Gantt)", "/tasks?view=gantt"),
    ("CRM", "/counterparties"),
    ("Müqavilələr", "/contracts"),
    ("Yeni müqavilə", "/contracts/create"),
    ("Bank", "/bank/transactions"),
    ("Yeni bank əməliyyatı", "/bank/transactions/create?direction=in"),
    ("Logistika", "/shipments"),
    ("Hesabatlar", "/reports"),
    ("Gəlir-xərc hesabatı", "/reports/income-expense"),
    ("Import", "/imports"),
    ("Tənzimləmələr", "/settings"),
    ("Rollar", "/settings/roles"),
    ("Giriş logları", "/settings/logs/logins"),
    ("Profil", "/profile"),
]

PASS: list[str] = []
FAIL: list[str] = []


def ok(m):
    PASS.append(m)
    print(f"  ok   {m}")


def bad(m):
    FAIL.append(m)
    print(f"  FAIL {m}")


def main() -> int:
    chrome = os.environ.get("CHROME_PATH") or r"C:\Program Files\Google\Chrome\Application\chrome.exe"
    if not os.path.exists(chrome):
        chrome = shutil.which("google-chrome") or shutil.which("chromium") or ""
    if not chrome:
        sys.exit("Chrome not found")
    if SHOTS:
        os.makedirs(SHOTS, exist_ok=True)

    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
    profile = tempfile.mkdtemp(prefix="glaust-check-")
    proc = subprocess.Popen(
        [chrome, "--headless=new", f"--remote-debugging-port={port}", f"--user-data-dir={profile}",
         "--no-first-run", "--disable-gpu", "--hide-scrollbars", "--remote-allow-origins=*", "about:blank"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    ws_url = ""
    for _ in range(80):
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{port}/json/version", timeout=1) as r:
                ws_url = json.load(r)["webSocketDebuggerUrl"]
            break
        except Exception:
            time.sleep(0.25)
    if not ws_url:
        proc.kill()
        sys.exit("DevTools did not open")

    ws = websocket.create_connection(ws_url, timeout=60)
    state = {"id": 0, "events": []}

    def send(method, params=None):
        state["id"] += 1
        ws.send(json.dumps({"id": state["id"], "method": method, "params": params or {}}))
        while True:
            message = json.loads(ws.recv())
            if message.get("id") == state["id"]:
                return message.get("result", {})
            if "method" in message:
                state["events"].append(message)

    target = send("Target.createTarget", {"url": "about:blank"})["targetId"]
    ws.close()
    ws = websocket.create_connection(f"ws://127.0.0.1:{port}/devtools/page/{target}", timeout=60)
    state["id"] = 0

    def js(expression, await_promise=False):
        result = send("Runtime.evaluate", {"expression": expression, "returnByValue": True, "awaitPromise": await_promise})
        if result.get("exceptionDetails"):
            raise RuntimeError(result["exceptionDetails"].get("text", "JS error"))
        return result.get("result", {}).get("value")

    def pump(rounds=4):
        # Events are only collected through normal send() round trips (short socket
        # timeouts would cut a WebSocket frame and break the connection).
        for _ in range(rounds):
            send("Runtime.evaluate", {"expression": "1", "returnByValue": True})
            time.sleep(0.2)

    def errors():
        out = []
        for e in state["events"]:
            if e.get("method") == "Log.entryAdded":
                entry = e["params"].get("entry", {})
                if entry.get("level") == "error":
                    out.append(entry.get("text", "")[:160])
            elif e.get("method") == "Runtime.exceptionThrown":
                d = e["params"].get("exceptionDetails", {})
                out.append((d.get("exception", {}).get("description") or d.get("text", "exception"))[:160])
            elif e.get("method") == "Runtime.consoleAPICalled" and e["params"].get("type") == "warning" and "Alpine" in json.dumps(e["params"].get("args", [])):
                out.append(" ".join(str(a.get("value", a.get("description", ""))) for a in e["params"].get("args", []))[:400])
            elif e.get("method") == "Runtime.consoleAPICalled" and e["params"].get("type") == "error":
                out.append(" ".join(str(a.get("value", a.get("description", ""))) for a in e["params"].get("args", []))[:160])
        return out

    def wait_for(expr, seconds=8.0):
        end = time.time() + seconds
        while time.time() < end:
            try:
                if js(expr):
                    return True
            except RuntimeError:
                pass
            time.sleep(0.25)
        return False

    def shot(name):
        if not SHOTS:
            return
        data = send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": False})["data"]
        with open(os.path.join(SHOTS, name + ".png"), "wb") as f:
            f.write(base64.b64decode(data))

    def viewport(w, h, mobile=False):
        send("Emulation.setDeviceMetricsOverride", {"width": w, "height": h, "deviceScaleFactor": 1, "mobile": mobile})

    try:
        send("Page.enable")
        send("Runtime.enable")
        send("Log.enable")
        viewport(1440, 960)
        print(f"\nGlaust MS browser check: {BASE}\n")

        # --- login page renders with its own CSS/JS and no console errors
        state["events"].clear()
        send("Page.navigate", {"url": BASE + "/login"})
        wait_for("document.readyState === 'complete'")
        pump()
        styled = js("getComputedStyle(document.body).fontFamily")
        ok("giriş səhifəsi stilləndi (IBM Plex)") if styled and "Plex" in styled else bad(f"giriş səhifəsinin şrifti: {styled}")
        errs = errors()
        ok("giriş səhifəsində konsol təmizdir") if not errs else bad(f"giriş səhifəsi konsol: {errs[0]}")
        shot("00-login")

        js(f"""(() => {{ const f = document.querySelector('form'); f.email.value = {json.dumps(EMAIL)};
                 f.password.value = {json.dumps(PASSWORD)}; f.submit(); }})()""")
        if not wait_for("location.pathname === '/' && !!document.querySelector('main#main')", 15):
            bad("giriş alınmadı")
            return 1
        ok("giriş edildi")

        # --- header widgets
        ok("Alpine işə düşdü") if wait_for("!!window.Alpine") else bad("Alpine yoxdur")
        if wait_for("document.querySelectorAll('.ticker-track li').length > 0", 15):
            n = js("document.querySelectorAll('.ticker-track li').length")
            label = js("document.querySelector('.ticker-wrap a span.font-mono')?.textContent?.trim()")
            ok(f"CBAR məzənnə lenti yükləndi ({n // 2} valyuta, bülleten {label})")
        else:
            bad("CBAR məzənnə lenti boş qaldı")
        moving = js("getComputedStyle(document.querySelector('.ticker-track')).animationName")
        ok(f"lent animasiyası: {moving}") if moving and moving != "none" else bad("lent animasiyası işləmir")
        my_day = wait_for("(() => { const b = document.querySelector('[aria-label=\"Bugünkü işlərim\"]'); return b && Alpine.$data(b).loading === false; })()", 10)
        ok("«Bugünkü işlərim» paneli yükləndi") if my_day else bad("«Bugünkü işlərim» yüklənmədi")

        # --- dashboard charts actually drawn
        js("window.scrollTo(0, document.body.scrollHeight)")
        time.sleep(1.5)
        js("window.scrollTo(0, 0)")
        drawn = wait_for("document.querySelectorAll('.apexcharts-canvas').length >= 3", 12)
        n = js("document.querySelectorAll('.apexcharts-canvas').length")
        ok(f"dashboard qrafikləri çəkildi ({n})") if drawn else bad(f"qrafiklər çəkilmədi ({n})")
        counted = js("[...document.querySelectorAll('[x-countup]')].every(e => /\\d/.test(e.textContent))")
        ok("KPI sayğacları dolu") if counted else bad("KPI sayğacları boş")
        time.sleep(0.8)
        shot("01-dashboard")

        # --- Ctrl+K palette with remote search
        js("window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }))")
        opened = wait_for("(() => { const i = document.querySelector('[aria-label=\"Komanda paneli\"] input'); return i && i.offsetParent !== null; })()", 4)
        ok("Ctrl+K komanda paneli açıldı") if opened else bad("Ctrl+K paneli açılmadı")
        if opened:
            js("(() => { const i = document.querySelector('[aria-label=\"Komanda paneli\"] input'); i.value = 'MQ'; i.dispatchEvent(new Event('input')); })()")
            found = wait_for("document.querySelectorAll('[aria-label=\"Komanda paneli\"] li a').length > 0", 6)
            ok("axtarış nəticə qaytardı") if found else bad("axtarış nəticəsiz")
            shot("02-palette")
            js("window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))")

        # --- dark theme
        js("Alpine.store('theme').set('dark')")
        time.sleep(0.6)
        dark = js("document.documentElement.classList.contains('dark') && getComputedStyle(document.body).backgroundColor")
        ok(f"qaranlıq tema ({dark})") if dark and dark != "rgb(244, 243, 239)" else bad(f"qaranlıq tema tətbiq olunmadı: {dark}")
        time.sleep(1.2)
        shot("03-dashboard-dark")
        js("Alpine.store('theme').set('light')")

        # --- every page: console, overflow, invisible headline
        for title, path in PAGES:
            state["events"].clear()
            send("Page.navigate", {"url": BASE + path})
            wait_for("document.readyState === 'complete' && !!window.Alpine", 10)
            time.sleep(0.8)
            pump()
            errs = [e for e in errors() if "favicon" not in e]
            overflow = js("document.documentElement.scrollWidth - document.documentElement.clientWidth")
            h1 = js("(() => { const h = document.querySelector('main h1'); if (!h) return 'no-h1'; const s = getComputedStyle(h); return s.opacity === '0' || s.visibility === 'hidden' ? 'hidden' : 'ok'; })()")
            problems = []
            if errs:
                problems.append(f"konsol: {errs[0]}")
            if isinstance(overflow, (int, float)) and overflow > 2:
                problems.append(f"{int(overflow)}px üfüqi daşma")
            if h1 != "ok":
                problems.append(f"başlıq: {h1}")
            bad(f"{title}: " + "; ".join(problems)) if problems else ok(f"{title}: təmiz")
            if path in ("/contracts/create", "/tasks", "/bank/transactions/create?direction=in", "/reports/income-expense", "/currency"):
                time.sleep(1.0)
                shot("10-" + title.replace(" ", "_").replace("(", "").replace(")", ""))

        # --- probe self-test: an injected console error must be caught
        state["events"].clear()
        js("console.error('glaust-selftest-error')")
        pump()
        ok("yoxlayıcının özü konsol xətasını tutur") if any("glaust-selftest" in e for e in errors()) else bad("yoxlayıcı konsol xətasını görmədi — probe etibarsızdır")

        # --- phone width
        viewport(390, 844, True)
        for title, path in [("Mobil: idarə paneli", "/"), ("Mobil: müqavilələr", "/contracts"), ("Mobil: bank", "/bank/transactions")]:
            send("Page.navigate", {"url": BASE + path})
            wait_for("document.readyState === 'complete' && !!window.Alpine", 10)
            time.sleep(1.2)
            overflow = js("document.documentElement.scrollWidth - document.documentElement.clientWidth")
            ok(f"{title}: daşma yoxdur") if isinstance(overflow, (int, float)) and overflow <= 2 else bad(f"{title}: {overflow}px daşma")
            shot("20-" + title.split(": ")[1].replace(" ", "_"))
        js("document.querySelector('[aria-label=\"Menyunu aç\"]').click()")
        time.sleep(0.6)
        nav_open = js("document.querySelector('aside[aria-label=\"Əsas naviqasiya\"]').getBoundingClientRect().left >= 0")
        ok("mobil menyu açılır") if nav_open else bad("mobil menyu açılmadı")
        shot("21-mobile-menu")
    finally:
        try:
            proc.kill()
        except Exception:
            pass
        shutil.rmtree(profile, ignore_errors=True)

    print(f"\n{len(PASS)} uğurlu, {len(FAIL)} problem")
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
