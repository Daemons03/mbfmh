"""V2.2: orchards use fertilizer from the silo."""
from test_production import build_here

from conftest import close_sheet, reload, settle, tap


def test_apple_orchard(game, farm):
    farm.patch({"level": 6, "cash": 50_000, "buildings.0.level": 4,  # barn: 350 per product
                "barn.items": {"fertilizer": 10}, "settings.speed": 1000})
    reload(game)
    build_here(game, 6, 4, "Pommeraie")

    tap(game, 6, 4)
    game.wait_for_selector("#sheet:not([hidden])")
    assert "🧪 10/10" in game.locator("#sheet-body").inner_text()
    game.get_by_role("button", name="Lancer la production").click()
    settle(game)
    close_sheet(game)

    game.locator(".building.production.ready").wait_for(timeout=25_000)  # 3 h / 1000 = 10.8 s
    tap(game, 6, 4)
    settle(game)
    assert farm.state()["barn"]["items"] == {"apple": 325, "leaves": 8}
