"""Step 8: windmill and chicken coop."""
from conftest import close_sheet, reload, settle, tap


def build_here(page, x, y, name):
    tap(page, x, y)
    page.wait_for_selector("#sheet:not([hidden])")
    page.locator(".row", has_text=name).get_by_role("button", name="Construire").click()
    page.wait_for_selector("#sheet[hidden]", state="attached")


def run_and_collect(page, x, y, timeout):
    tap(page, x, y)
    page.wait_for_selector("#sheet:not([hidden])")
    page.get_by_role("button", name="Lancer la production").click()
    settle(page)
    close_sheet(page)
    page.locator(f".building.production.ready").first.wait_for(timeout=timeout)
    tap(page, x, y)  # one-tap collect
    settle(page)


def test_corn_to_feed_to_eggs(game, farm):
    farm.patch({"barn.items": {"corn": 30}, "settings.speed": 100, "cash": 10_000})
    reload(game)
    build_here(game, 6, 4, "Moulin à grain")
    build_here(game, 8, 4, "Poulailler")

    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    body = game.locator("#sheet-body").inner_text()
    assert "🌽 10/10" in body and "🥣 20" in body and "6 s · +3 XP" in body
    close_sheet(game)

    run_and_collect(game, 6, 4, timeout=10_000)
    assert farm.state()["barn"]["items"] == {"corn": 20, "chicken_feed": 20}
    run_and_collect(game, 6, 4, timeout=10_000)
    assert farm.state()["barn"]["items"] == {"corn": 10, "chicken_feed": 40}

    run_and_collect(game, 8, 4, timeout=25_000)  # 1800 s / 100
    assert farm.state()["barn"]["items"] == {"corn": 10, "chicken_feed": 19, "egg": 21, "dung": 7}
    assert farm.state()["xp"] == 3 + 3 + 5


def test_missing_inputs(game, farm):
    farm.patch({"barn.items": {"corn": 9}, "cash": 10_000})
    reload(game)
    build_here(game, 6, 4, "Moulin à grain")

    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    assert game.locator("#sheet .chip.missing").inner_text() == "🌽 9/10"
    button = game.get_by_role("button", name="Ingrédients manquants")
    assert button.is_disabled()
