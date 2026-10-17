"""V2.1: animals eat the windmill feed."""
from test_production import build_here, run_and_collect

from conftest import reload, tap


def test_pigsty_eats_feed(game, farm):
    farm.patch({"level": 8, "cash": 10_000, "barn.items": {"chicken_feed": 30}, "settings.speed": 100})
    reload(game)
    build_here(game, 6, 4, "Porcherie")

    run_and_collect(game, 6, 4, timeout=45_000)  # 3600 s / 100

    assert farm.state()["barn"]["items"] == {"chicken_feed": 4, "pig": 130, "dung": 35}


def test_cowshed_warns_when_barn_is_too_small(game, farm):
    farm.patch({"level": 14, "cash": 20_000, "barn.items": {"chicken_feed": 30}})
    reload(game)
    build_here(game, 6, 4, "Étable")

    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    assert "trop petite pour 230 🥛" in game.locator("#sheet-body").inner_text().replace(" ", " ")
    assert game.get_by_role("button", name="Grange trop petite").is_disabled()
