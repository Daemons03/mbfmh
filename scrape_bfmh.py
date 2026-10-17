#!/usr/bin/env python3
"""
Scraper bfmhfansite.com -> JSON, pour usage personnel (Ferme Tranquille).

Le site est en Next.js : les tableaux sont rendus en JavaScript, donc on passe
par un vrai navigateur (Playwright). Pour chaque page :
  - les tableaux HTML sont parsés en JSON (valeur + unité déduite de l'icône),
  - les réponses JSON / RSC chargées par la page sont aussi sauvegardées brutes.

Installation :
    pip install playwright beautifulsoup4
    playwright install chromium

Usage :
    python scrape_bfmh.py                      # tout, 2 s entre pages
    python scrape_bfmh.py --delay 3 --max-pages 50
    python scrape_bfmh.py --force              # re-télécharge les pages déjà faites
"""
from __future__ import annotations

import argparse
import json
import re
import time
import urllib.robotparser
from collections import deque
from pathlib import Path
from urllib.parse import parse_qs, unquote, urljoin, urlparse

from bs4 import BeautifulSoup, Comment, NavigableString
from playwright.sync_api import TimeoutError as PWTimeout
from playwright.sync_api import sync_playwright

BASE = "https://www.bfmhfansite.com"
HOST = "www.bfmhfansite.com"
UA = "FermeTranquille-scraper/1.0 (usage personnel, non commercial)"

# Points d'entrée : une page par catégorie, le reste est découvert via les liens.
SEEDS = [
    "/buildings/house",              # bâtiments classiques
    "/buildings/flowerbed",          # décorations
    "/buildings/cowshed-hub",        # hubs
    "/buildings/gnome-house",        # bâtiments spéciaux
    "/buildings/tree-of-lanterns",   # décorations spéciales
    "/buildings/farmhouse",          # autres
    "/buildings/land",               # extensions de terrain
    "/buildings/premium-house",      # premium
    "/buildings/chicken-coop",
    "/buildings/free-range-chicken-coop",
    "/products",
    "/market/upgrades/contract",
    "/library/wildflower",
    "/fishing",
]

# On ne suit que les pages utiles au jeu de base (pas d'événements, co-op, etc.).
ALLOWED_PREFIXES = ("/buildings/", "/products", "/market/upgrades/", "/library/", "/fishing")

NUMBER_RE = re.compile(r"[+-]?\d[\d,]*(?:\.\d+)?")


# ---------------------------------------------------------------- parsing

def icon_name(img) -> str:
    """Nom d'icône à partir du src (plus fiable que alt sur ce site)."""
    src = img.get("src") or ""
    parsed = urlparse(src)
    if parsed.path.startswith("/_next/image"):
        inner = parse_qs(parsed.query).get("url", [""])[0]
        src = unquote(inner)
        parsed = urlparse(src)
    stem = Path(parsed.path).stem
    return stem or (img.get("alt") or "").strip()


def to_number(text: str):
    text = text.strip()
    if text in ("", "?", "-", "—"):
        return None
    match = NUMBER_RE.fullmatch(text)
    if not match:
        return None
    raw = text.replace(",", "")
    return float(raw) if "." in raw else int(raw)


def parse_cell(cell) -> dict:
    """
    Découpe une cellule en morceaux : chaque texte suivi d'une icône devient
    {"value": 1750, "unit": "cash"} ou {"label": "Corn", "icon": "corn"}.
    """
    parts, buf = [], ""
    for node in cell.descendants:
        if isinstance(node, Comment):
            continue
        if isinstance(node, NavigableString):
            buf += str(node)
        elif node.name == "br":
            buf += " "
        elif node.name == "img":
            unit = icon_name(node)
            chunk = buf.strip()
            if chunk:
                value = to_number(chunk)
                if value is not None or chunk == "?":
                    parts.append({"value": value, "unit": unit})
                else:
                    parts.append({"label": chunk, "icon": unit})
            else:
                parts.append({"icon": unit})
            buf = ""
    result = {"text": cell.get_text(" ", strip=True), "parts": parts}
    tail = buf.strip()
    if tail:
        result["tail"] = tail
    return result


def parse_table(table) -> dict:
    rows = table.find_all("tr")
    headers: list[str] = []
    body_rows = rows
    if rows and rows[0].find("th"):
        for th in rows[0].find_all(["th", "td"]):
            text = th.get_text(" ", strip=True)
            if not text:
                img = th.find("img")
                text = icon_name(img) if img else ""
            headers.append(text)
        body_rows = rows[1:]
    data = []
    for tr in body_rows:
        cells = tr.find_all(["td", "th"])
        if cells:
            data.append([parse_cell(c) for c in cells])
    return {"headers": headers, "rows": data}


def page_title(soup) -> str:
    h2 = soup.find("h2")
    if h2:
        return h2.get_text(" ", strip=True)
    return soup.title.get_text(strip=True) if soup.title else ""


def discover_links(soup, current_url: str) -> set[str]:
    found = set()
    for a in soup.find_all("a", href=True):
        url = urlparse(urljoin(current_url, a["href"]))
        if url.netloc != HOST:
            continue
        path = url.path.rstrip("/") or "/"
        if path.startswith(ALLOWED_PREFIXES):
            found.add(path)
    return found


# ---------------------------------------------------------------- crawl

def load_robots() -> urllib.robotparser.RobotFileParser | None:
    rp = urllib.robotparser.RobotFileParser(BASE + "/robots.txt")
    try:
        rp.read()
        return rp
    except Exception as exc:  # robots.txt injoignable : on continue prudemment
        print(f"[warn] robots.txt illisible ({exc}), on continue avec délai")
        return None


def out_path(root: Path, path: str) -> Path:
    rel = path.strip("/") or "index"
    return root / "pages" / f"{rel}.json"


def crawl(args) -> None:
    root = Path(args.out)
    (root / "pages").mkdir(parents=True, exist_ok=True)
    (root / "network").mkdir(parents=True, exist_ok=True)
    robots = load_robots()

    index: dict[str, dict] = {}
    index_file = root / "index.json"
    if index_file.exists() and not args.force:
        index = json.loads(index_file.read_text(encoding="utf-8"))

    queue = deque(SEEDS)
    seen: set[str] = set()
    fetched = 0

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=not args.headed)
        context = browser.new_context(user_agent=UA, locale="en-US")
        page = context.new_page()
        responses = []
        page.on("response", lambda r: responses.append(r))

        while queue and fetched < args.max_pages:
            path = queue.popleft()
            if path in seen:
                continue
            seen.add(path)
            url = BASE + path
            target = out_path(root, path)

            if robots and not robots.can_fetch(UA, url):
                print(f"[skip robots] {path}")
                continue

            # Reprise : page déjà faite -> on relit juste ses liens.
            if target.exists() and not args.force:
                saved = json.loads(target.read_text(encoding="utf-8"))
                for link in saved.get("links", []):
                    if link not in seen:
                        queue.append(link)
                continue

            responses.clear()
            print(f"[{fetched + 1}] {path}")
            try:
                page.goto(url, wait_until="networkidle", timeout=45_000)
            except PWTimeout:
                print("   [warn] timeout chargement, on parse quand même")
            try:
                page.wait_for_selector("table", timeout=8_000)
            except PWTimeout:
                pass  # page sans tableau (texte seul)

            soup = BeautifulSoup(page.content(), "html.parser")
            links = sorted(discover_links(soup, url))
            record = {
                "url": url,
                "path": path,
                "category": path.strip("/").split("/")[0],
                "title": page_title(soup),
                "tables": [parse_table(t) for t in soup.find_all("table")],
                "links": links,
                "scraped_at": int(time.time()),
            }
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(json.dumps(record, ensure_ascii=False, indent=2), encoding="utf-8")

            # Données brutes chargées par la page (JSON API ou flux RSC Next.js).
            raw_dump = []
            for resp in responses:
                ctype = resp.headers.get("content-type", "")
                if urlparse(resp.url).netloc != HOST:
                    continue
                if "json" not in ctype and "x-component" not in ctype:
                    continue
                try:
                    body = resp.json() if "json" in ctype else resp.text()
                except Exception:
                    continue
                raw_dump.append({"url": resp.url, "content_type": ctype, "body": body})
            if raw_dump:
                net_file = root / "network" / f"{path.strip('/').replace('/', '__')}.json"
                net_file.write_text(json.dumps(raw_dump, ensure_ascii=False, indent=2), encoding="utf-8")

            index[path] = {"title": record["title"], "tables": len(record["tables"]), "file": str(target.relative_to(root))}
            index_file.write_text(json.dumps(index, ensure_ascii=False, indent=2), encoding="utf-8")

            for link in links:
                if link not in seen:
                    queue.append(link)

            fetched += 1
            time.sleep(args.delay)

        browser.close()

    print(f"\nTerminé : {fetched} page(s) téléchargée(s), {len(index)} au total dans {index_file}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Scrape bfmhfansite.com vers JSON")
    parser.add_argument("--out", default="scraped", help="dossier de sortie (défaut: scraped)")
    parser.add_argument("--delay", type=float, default=2.0, help="pause entre pages en secondes (défaut: 2)")
    parser.add_argument("--max-pages", type=int, default=400, help="nombre max de pages à télécharger")
    parser.add_argument("--force", action="store_true", help="re-télécharger les pages déjà présentes")
    parser.add_argument("--headed", action="store_true", help="afficher le navigateur (debug)")
    crawl(parser.parse_args())


if __name__ == "__main__":
    main()
