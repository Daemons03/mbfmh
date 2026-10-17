"""Show animals: chests, cards, free activation."""
from conftest import close_sheet, open_tab, reload, settle


def test_chests_and_activation(game, farm):
    farm.patch({"materials.tokens": 100, "settings.speed": 100,
                "show": {"seed": "e2e", "opened": 0, "token_day": "2000-01-01", "animals": {}, "active": {}}})
    reload(game)
    open_tab(game, "show")
    body = game.locator("#sheet-body")
    assert "🎟️" in body.inner_text()

    game.get_by_role("button", name="Ouvrir 11 coffres (100 🎟️)").click()
    settle(game)
    state = farm.state()
    assert state["materials"]["tokens"] == 20, "100 spent, +20 daily on the reload"
    assert "🎉" in body.inner_text()
    draw = state["show"]["last_draw"]
    if "animal" not in draw:
        return  # blueprints this time (0.4 %): nothing to activate

    owned = state["show"]["animals"][draw["animal"]]
    assert owned["level"] >= 1
    card = game.locator("#sheet .recipe").first
    card.get_by_role("button").first.click()  # shortest duration
    settle(game)
    assert len(farm.state()["show"]["active"]) == 1
    assert "Animaux au travail : 1 / 1" in body.inner_text()
    close_sheet(game)
