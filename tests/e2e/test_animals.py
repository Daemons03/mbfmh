"""Animals eat their own feed and cost cash per run."""
from test_production import build_here, recipe_card, run_and_collect

from conftest import reload, tap


def test_pigsty_eats_pig_feed(game, farm):
    farm.patch({"level": 8, "cash": 10_000, "barn.items": {"pig_feed": 12}, "settings.speed": 1000})
    farm.add_house()
    reload(game)
    build_here(game, 6, 4, "Porcherie")

    run_and_collect(game, 6, 4, "Cochon", timeout=15_000)  # 4200 s / 1000

    state = farm.state()
    assert state["barn"]["items"] == {"pig_feed": 2, "pig": 130, "dung": 35}
    assert state["cash"] == 10_000 - 2000 - farm.cost(24)


def test_cowshed_warns_when_barn_is_too_small(game, farm):
    farm.patch({"level": 14, "cash": 20_000, "barn.items": {"cattle_feed": 10}})
    farm.add_house()
    reload(game)
    build_here(game, 6, 4, "Étable")

    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    card = recipe_card(game, "Lait")
    assert "trop petite pour 230 🥛" in card.inner_text().replace(" ", " ")
    assert card.get_by_role("button", name="Grange trop petite").is_disabled()
