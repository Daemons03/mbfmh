"""Sewing workshop: instant crafting."""
from test_production import recipe_card

from conftest import close_sheet, open_tab, reload, row, settle


def test_craft_light_socks(game, farm):
    farm.patch({"level": 100, "cash": 5_000_000, "buildings.0.level": 25, "barn.items": {"sheep_wool": 1600}})
    farm.add_house()
    reload(game)
    open_tab(game, "build")
    game.get_by_role("tab", name="Extérieur").click()
    row(game, "Atelier de couture").get_by_role("button", name="Construire").click()
    settle(game)
    close_sheet(game)
    assert "x" not in next(b for b in farm.state()["buildings"] if b["type"] == "sewing_workshop"), "outside the farm"

    game.locator(".outside[data-outside=sewing_workshop]").click()
    game.wait_for_selector("#sheet:not([hidden])")
    card = recipe_card(game, "Chaussettes claires")
    assert "Instantané" in card.inner_text()
    card.get_by_role("button", name="Fabriquer").click()
    settle(game)

    assert farm.state()["barn"]["items"] == {"sheep_wool": 100, "light_socks": 10}
    assert recipe_card(game, "Chaussettes violettes").get_by_role("button", name="Niv. 185").is_disabled()
