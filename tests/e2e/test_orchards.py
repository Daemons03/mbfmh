"""Orchards use fertilizer; the silo makes it from dung in batches."""
from test_production import build_here, recipe_card, run_and_collect

from conftest import reload, tap


def test_silo_then_apple_orchard(game, farm):
    farm.patch({"level": 6, "cash": 50_000, "buildings.0.level": 4,  # barn: 350 per product
                "barn.items": {"dung": 210}, "settings.speed": 1000})
    farm.add_house()  # silo 4 + orchard 4 workers
    reload(game)
    build_here(game, 6, 4, "Silo")  # 3 × 2
    build_here(game, 9, 4, "Pommeraie")

    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    assert "1 / 3 lots" in recipe_card(game, "Engrais").inner_text(), "level 1 holds 30 fertilizer"
    game.keyboard.press("Escape")

    run_and_collect(game, 6, 4, "Engrais", timeout=15_000)  # 56 min / 1000
    assert farm.state()["barn"]["items"] == {"fertilizer": 10}

    run_and_collect(game, 9, 4, "Pomme", timeout=25_000)  # 3 h / 1000
    state = farm.state()
    assert state["barn"]["items"] == {"apple": 325, "leaves": 8}
    assert state["cash"] == 50_000 - 1000 - 3500 - farm.cost(209) - farm.cost(496)
