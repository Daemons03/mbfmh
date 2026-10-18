"""Special items: premium seeds and humus, special seeds, instant feed."""
from test_production import build_here, recipe_card

from conftest import close_sheet, open_tab, reload, row, settle, tap, text


def test_premium_seed_and_humus_on_a_field(game, farm):
    farm.patch({"barn.items": {"premium_seed_corn": 1, "premium_humus": 2, "humus": 1}})
    reload(game)
    tap(game, 3, 0)
    game.wait_for_selector("#sheet:not([hidden])")

    game.get_by_label("humus premium", exact=False).check()
    corn = row(game, "Maïs")
    assert "Gratuit" in text(corn)
    corn.get_by_role("button", name="Premium").click()  # 10 x 1.1 = 11, x 1.5 = 16
    settle(game)

    state = farm.state()
    field = next(b for b in state["buildings"] if b.get("x") == 2 and b.get("y") == 0)
    assert field["job"]["outputs"] == {"corn": 16}
    assert state["cash"] == 2000
    assert state["barn"]["items"] == {"premium_humus": 1, "humus": 1}


def test_roses_and_golden_flowers(game, farm):
    farm.patch({"barn.items": {"rose_seed": 1, "golden_flower_seed": 1}})
    reload(game)
    tap(game, 3, 0)
    game.wait_for_selector("#sheet:not([hidden])")
    assert "+10 XP tout de suite" in text(row(game, "Roses"))
    row(game, "Roses").get_by_role("button", name="Planter").click()
    settle(game)
    assert farm.state()["xp"] == 10

    row(game, "Fleurs d'or").get_by_role("button", name="Planter").click()
    settle(game)
    assert "Fleurs d'or" in game.locator("#sheet-body").inner_text()
    field = next(b for b in farm.state()["buildings"] if b.get("x") == 2 and b.get("y") == 0)
    assert field["job"]["reward"] == {"gold": 200}


def test_instant_feed_and_special_items_in_the_barn(game, farm):
    farm.patch({"cash": 10_000, "barn.items": {"instant_feed": 1}})
    farm.add_house()
    reload(game)
    build_here(game, 6, 4, "Poulailler")
    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    recipe_card(game, "Œuf").get_by_role("button", name="Instantané").click()
    settle(game)
    assert farm.state()["barn"]["items"] == {}
    close_sheet(game)

    tap(game, 6, 4)  # ready at once: one-tap collect
    settle(game)
    assert farm.state()["barn"]["items"] == {"egg": 21, "dung": 7}

    farm.patch({"barn.items": {"premium_feed": 3}})
    reload(game)
    open_tab(game, "barn")
    feed = row(game, "Nourriture premium")
    assert "objet spécial" in text(feed)
    assert feed.get_by_role("button").count() == 0, "never sold"
