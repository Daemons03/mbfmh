"""Step 9: land expansions."""
from conftest import close_sheet, grid_side, open_tab, reload, row, settle, tap


def test_locked_block_opens_land_panel_and_level_gates_it(game):
    tap(game, 9, 9)
    game.wait_for_selector("#sheet:not([hidden])")
    assert game.locator("#sheet-title").inner_text() == "🗺️ Terrain"
    assert "Extension n° 7 — niv. 4 requis" in game.locator("#sheet-body").inner_text()
    buttons = game.locator("#sheet .contract .btn-primary")
    assert [b.is_disabled() for b in buttons.all()] == [True, True]


def test_buy_expansions_and_build_on_them(game, farm):
    farm.patch({"level": 10, "gold": 2000, "cash": 20_000})
    farm.add_house()  # the field built on the new land needs 10 workers
    reload(game)
    open_tab(game, "land")
    options = game.locator("#sheet .contract .row")

    options.nth(0).get_by_role("button", name="Acheter").click()  # #7 for 50 gold
    game.wait_for_function("() => document.querySelector('#sheet-body').innerText.includes('n° 8')")
    options.nth(1).get_by_role("button", name="Acheter").click()  # #8 for 1 200 cash
    game.wait_for_function("() => document.querySelector('#sheet-body').innerText.includes('n° 9')")
    options.nth(1).get_by_role("button", name="Acheter").click()  # #9 for 4 500 cash
    game.wait_for_function("() => document.querySelector('#sheet-body').innerText.includes('n° 10')")
    close_sheet(game)

    state = farm.state()
    assert (state["expansions"], state["gold"], state["cash"]) == (9, 1950, 20_000 - 1200 - 4500)
    assert grid_side(game) == 16
    assert game.locator("#grid .cell.locked").count() == 16 * 16 - 9 * 16

    tap(game, 9, 9)
    game.wait_for_selector("#sheet:not([hidden])")
    row(game, "Champ").get_by_role("button", name="Construire").click()
    settle(game)
    assert {"x": 9, "y": 9}.items() <= farm.state()["buildings"][-1].items()
