"""V2.3: dairy (several recipes, batches) and humus on fields."""
from test_production import build_here, recipe_card, run_and_collect

from conftest import close_sheet, reload, row, settle, tap


def test_dairy_cream_batches(game, farm):
    farm.patch({"level": 60, "cash": 6_000_000, "buildings.0.level": 25, "barn.items": {"milk": 600},
                "settings.speed": 1000})
    farm.add_house()
    reload(game)
    build_here(game, 6, 4, "Laiterie")

    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    names = game.locator("#sheet .recipe .row-title").all_inner_texts()
    assert len(names) == 4 and any("Crème" in n for n in names)
    assert "1 / 4 lots" in recipe_card(game, "Crème").inner_text()
    assert recipe_card(game, "Fromage").locator(".chip.missing").inner_text().startswith("🫒 0/110"), "no olive oil yet"
    close_sheet(game)

    run_and_collect(game, 6, 4, "Crème", timeout=30_000, batches=2)  # 2 x 3 h / 1000 = 21.6 s
    assert farm.state()["barn"]["items"] == {"milk": 100, "cream": 120}


def test_humus_on_a_field(game, farm):
    farm.patch({"barn.items": {"humus": 2}, "settings.speed": 100})
    reload(game)
    tap(game, 3, 0)
    game.wait_for_selector("#sheet:not([hidden])")
    game.get_by_label("+25 %", exact=False).check()
    assert "· 12 🌽" in row(game, "Maïs").inner_text()
    row(game, "Maïs").get_by_role("button", name="Planter").click()
    settle(game)
    close_sheet(game)

    game.wait_for_selector(".building.field.ready", timeout=10_000)
    tap(game, 3, 0)
    settle(game)
    assert farm.state()["barn"]["items"] == {"humus": 1, "corn": 12}
