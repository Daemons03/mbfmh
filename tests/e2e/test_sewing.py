"""Sewing workshop: instant crafting."""
from test_production import build_here, recipe_card

from conftest import reload, settle, tap


def test_craft_light_socks(game, farm):
    farm.patch({"level": 100, "cash": 5_000_000, "buildings.0.level": 25, "barn.items": {"sheep_wool": 1600}})
    farm.add_house()
    reload(game)
    build_here(game, 6, 4, "Atelier de couture")

    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    card = recipe_card(game, "Chaussettes claires")
    assert "Instantané" in card.inner_text()
    card.get_by_role("button", name="Fabriquer").click()
    settle(game)

    assert farm.state()["barn"]["items"] == {"sheep_wool": 100, "light_socks": 10}
    assert recipe_card(game, "Chaussettes violettes").get_by_role("button", name="Niv. 185").is_disabled()
