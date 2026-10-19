"""
Browser tests (Playwright + pytest) against the real app: php -S + the MySQL
database from config/config.php, using a dedicated "e2e-playwright" account
that is recreated for each test and deleted afterwards.

Setup (once):
    python3 -m venv .venv
    .venv/bin/pip install -r tests/e2e/requirements.txt
    .venv/bin/playwright install chromium

Run:
    .venv/bin/python -m pytest tests/e2e            # FT_E2E_HEADED=1 to watch
"""
from __future__ import annotations

import json
import os
import re
import socket
import subprocess
import time
from pathlib import Path

import pytest
from playwright.sync_api import Page, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ARTIFACTS = Path(__file__).parent / "artifacts"
LOGIN = "e2e-playwright"
PASSWORD = "e2e-password-123"


# ------------------------------------------------------------------ server & data

def _free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


@pytest.fixture(scope="session")
def base_url():
    port = _free_port()
    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", "public"],
        cwd=ROOT,
        env={**os.environ, "PHP_CLI_SERVER_WORKERS": "4"},
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    url = f"http://127.0.0.1:{port}/"
    for _ in range(50):
        try:
            socket.create_connection(("127.0.0.1", port), timeout=0.2).close()
            break
        except OSError:
            time.sleep(0.1)
    yield url
    server.terminate()
    server.wait(timeout=5)


class Farm:
    """The E2E account's farm, edited server-side through tests/e2e/helper.php."""

    def _run(self, *args: str):
        out = subprocess.run(["php", "tests/e2e/helper.php", *args], cwd=ROOT, check=True,
                             capture_output=True, text=True).stdout
        return json.loads(out)

    def reset(self):
        self._run("reset-user", LOGIN, PASSWORD)

    def delete(self):
        self._run("delete-user", LOGIN)

    def patch(self, values: dict):
        """Sets dotted paths (e.g. {"settings.speed": 100}); bumps the version like another device would."""
        self._run("patch-state", LOGIN, json.dumps(values))

    def state(self) -> dict:
        return self._run("get-state", LOGIN)["state"]

    def log(self) -> list[str]:
        return self._run("log", LOGIN)

    def reset_bots(self):
        """Empties the bot world (shared by every test): a fresh one comes with the next request."""
        self._run("reset-bots", LOGIN)

    def add_house(self, x: int = 10, y: int = 0):
        """A house on the farm (2x2): 10 more workers, -35 happiness."""
        self._run("add-house", LOGIN, str(x), str(y))

    def cost(self, cash: int) -> int:
        """Cash actually paid for a production cost, after the happiness effect."""
        factor = self._run("view", LOGIN)["happiness"]["cost_factor"]
        return int(cash * factor + 0.5)


@pytest.fixture
def farm():
    f = Farm()
    f.reset()
    yield f
    f.delete()


# ------------------------------------------------------------------ browser

@pytest.fixture(scope="session")
def browser():
    with sync_playwright() as p:
        b = p.chromium.launch(headless=not os.environ.get("FT_E2E_HEADED"))
        yield b
        b.close()


@pytest.fixture
def make_page(browser, request):
    """make_page(width=390, height=844, scheme='light') -> Page that fails the test on JS errors."""
    contexts, errors = [], []

    def make(width=390, height=844, scheme="light"):
        ctx = browser.new_context(viewport={"width": width, "height": height}, color_scheme=scheme,
                                  has_touch=width < 600, locale="fr-FR")
        contexts.append(ctx)
        page = ctx.new_page()
        page.on("pageerror", lambda e: errors.append(f"pageerror: {e}"))
        # HTTP 401/409/422 are expected answers; the browser logs them as resource errors.
        page.on("console", lambda m: m.type == "error" and "Failed to load resource" not in m.text
                and errors.append(f"console: {m.text}"))
        page.on("dialog", lambda d: d.accept("RESET") if d.type == "prompt" else d.accept())
        return page

    yield make

    failed = getattr(request.node, "rep_call", None) is not None and request.node.rep_call.failed
    for i, ctx in enumerate(contexts):
        if failed:
            ARTIFACTS.mkdir(exist_ok=True)
            for j, page in enumerate(ctx.pages):
                page.screenshot(path=ARTIFACTS / f"{request.node.name}-{i}-{j}.png")
        ctx.close()
    assert errors == [], errors


@pytest.hookimpl(hookwrapper=True)
def pytest_runtest_makereport(item, call):
    outcome = yield
    report = outcome.get_result()
    if report.when == "call":
        item.rep_call = report


@pytest.fixture
def page(make_page) -> Page:
    return make_page()


@pytest.fixture
def game(page, farm, base_url) -> Page:
    """Logged-in game page on a fresh farm."""
    login(page, base_url)
    return page


# ------------------------------------------------------------------ helpers

def login(page: Page, base_url: str, password: str = PASSWORD):
    page.goto(base_url)
    page.wait_for_selector("#login:not([hidden])")
    page.fill("[name=login]", LOGIN)
    page.fill("[name=password]", password)
    page.click("button[type=submit]")
    if password == PASSWORD:
        page.wait_for_selector("#grid .building")


def reload(page: Page):
    page.reload()
    page.wait_for_selector("#grid .building")


def cell_center(page: Page, x: int, y: int) -> tuple[float, float]:
    """Screen centre of grid cell (x, y) (a diamond on the isometric map), scrolled into view."""
    cell = page.locator(f'#grid .cell[data-x="{x}"][data-y="{y}"]')
    cell.evaluate("e => e.scrollIntoView({ block: 'center', inline: 'center' })")
    box = cell.bounding_box()
    return box["x"] + box["width"] / 2, box["y"] + box["height"] / 2


def tap(page: Page, x: int, y: int):
    """Clicks grid cell (x, y) with the mouse."""
    page.mouse.click(*cell_center(page, x, y))


def grid_side(page: Page) -> int:
    return round(page.locator("#grid .cell").count() ** 0.5)


OUTSIDE = {"market": "market", "show": "show_animal", "library": "library", "cafe": "cafe"}


def open_tab(page: Page, tab: str):
    """Bottom tab, or for outside buildings (market, show, library, café) their lot on the map."""
    if tab in OUTSIDE:
        page.locator(f'.outside[data-outside={OUTSIDE[tab]}]').click()
    else:
        page.click(f"#tabs [data-tab={tab}]")
    page.wait_for_selector("#sheet:not([hidden])")


def close_sheet(page: Page):
    page.keyboard.press("Escape")
    page.wait_for_selector("#sheet[hidden]", state="attached")


def text(locator) -> str:
    """inner_text with fr-FR thousand separators (narrow no-break spaces) turned into plain spaces."""
    return locator.inner_text().replace("\u202f", " ").replace("\u00a0", " ")


def cash_text(page: Page) -> str:
    return text(page.locator(".wallet .res").first)


def row(page: Page, title: str):
    """The list row whose title is exactly `title` (not "Moulin premium" for "Moulin")."""
    return page.locator(".row").filter(has=page.locator(".row-title", has_text=re.compile(f"^{re.escape(title)}$")))


def settle(page: Page):
    """Waits for the in-flight request to land (one request at a time in the app)."""
    page.wait_for_timeout(400)
