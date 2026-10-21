"""Production buildings: recipes (run / batch), windmill feeds, chicken coop."""
import re

from conftest import close_sheet, reload, row, settle, tap


def build_here(page, x, y, name):
    tap(page, x, y)
    page.wait_for_selector("#sheet:not([hidden])")
    for tab in ("Normaux", "Premium", "Plein air", "Décoration"):  # the row is in one category tab
        page.get_by_role("tab", name=tab).click()
        if row(page, name).count():
            break
    row(page, name).get_by_role("button", name="Construire").click()
    page.wait_for_selector("#sheet[hidden]", state="attached")


def recipe_card(page, product_name):
    return page.locator("#sheet .recipe", has_text=product_name)


def run_and_collect(page, x, y, product_name, timeout, batches=1):
    tap(page, x, y)
    page.wait_for_selector("#sheet:not([hidden])")
    card = recipe_card(page, product_name)
    minus = card.get_by_role("button", name="−")
    while minus.count() and minus.is_enabled():  # the panel remembers the last quantity
        minus.click()
    for _ in range(batches - 1):
        card.get_by_role("button", name="+").click()
    card.get_by_role("button", name="Lancer").click()
    settle(page)
    close_sheet(page)
    page.locator(".building.production.ready").first.wait_for(timeout=timeout)
    tap(page, x, y)  # one-tap collect
    settle(page)


def test_windmill_batches_then_chickens(game, farm):
    farm.patch({"barn.items": {"corn": 60}, "settings.speed": 100, "cash": 10_000})
    farm.add_house()  # windmill 4 + coop 5 workers
    reload(game)
    build_here(game, 6, 4, "Moulin")
    build_here(game, 8, 4, "Poulailler")

    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    assert game.locator("#sheet .recipe").count() == 7, "one feed per species"
    card = recipe_card(game, "Nourriture pour volaille")
    assert "🌽 25/25" in card.inner_text()
    assert "1 / 2 lots" in card.inner_text()
    card.get_by_role("button", name="+").click()
    assert "2 / 2 lots" in card.inner_text() and "🌽 50/50" in card.inner_text()
    assert recipe_card(game, "Nourriture pour cochons").get_by_role("button", name="Niv. 8").is_disabled()
    close_sheet(game)

    run_and_collect(game, 6, 4, "Nourriture pour volaille", timeout=20_000, batches=2)  # 2 x 180 s / 100
    assert farm.state()["barn"]["items"] == {"corn": 10, "chicken_feed": 20}

    run_and_collect(game, 8, 4, "Œuf", timeout=10_000)  # 390 s / 100
    state = farm.state()
    assert state["barn"]["items"] == {"corn": 10, "chicken_feed": 10, "egg": 21, "dung": 7}
    assert state["cash"] == 10_000 - 50 - 1500 - farm.cost(2 * 2) - farm.cost(4)
    assert (state["level"], state["xp"]) == (2, 2 * 50 + 5 - 50)


def test_missing_inputs(game, farm):
    farm.patch({"barn.items": {"chicken_feed": 9}, "cash": 10_000})
    farm.add_house()
    reload(game)
    build_here(game, 8, 4, "Poulailler")

    tap(game, 8, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    card = recipe_card(game, "Œuf")
    assert card.locator(".chip.missing").inner_text() == "🐥 9/10 (9)"
    assert card.get_by_role("button", name="Ingrédients manquants").is_disabled()

    farm.patch({"barn.items": {"chicken_feed": 180}})
    reload(game)
    tap(game, 8, 4)
    assert recipe_card(game, "Œuf").locator(".chip").first.inner_text() == "🐥 10/10 (180)", "stock in the barn"


def test_start_every_idle_coop_at_once(game, farm):
    farm.patch({"cash": 20_000, "barn.items": {"chicken_feed": 25}})
    farm.add_house()
    farm.add_house(10, 2)
    reload(game)
    for x in (4, 6, 8):  # animal buildings are unlimited
        build_here(game, x, 4, "Poulailler")
    coops = [b["id"] for b in farm.state()["buildings"] if b["type"] == "chicken_coop"]
    assert len(coops) == 3

    tap(game, 8, 4)
    recipe_card(game, "Œuf").get_by_role("button", name="Tout (3)").click()
    game.wait_for_selector(".toast.warn >> text=1 bâtiment reste libre")
    state = farm.state()
    started = [b["id"] for b in state["buildings"] if b["id"] in coops and "job" in b]
    assert len(started) == 2, "25 feed: two coops of 10"
    assert state["barn"]["items"] == {"chicken_feed": 5}
    assert farm.log()[-1] == "start_production_all"


def test_silos_have_no_start_all(game, farm):
    farm.patch({"level": 10, "cash": 50_000, "barn.items": {"dung": 500}})
    farm.add_house()
    farm.add_house(10, 2)
    reload(game)
    for x in (6, 9):  # 3 × 2 each
        build_here(game, x, 4, "Silo")
    tap(game, 9, 4)
    card = recipe_card(game, "Engrais")
    card.get_by_role("button", name="Lancer").wait_for()
    assert card.get_by_role("button", name=re.compile("Tout")).count() == 0
