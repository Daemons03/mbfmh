"""Café-boutique: build it, serve recipes, satisfaction milestones."""
from conftest import close_sheet, open_tab, reload, row, settle


def test_build_and_serve(game, farm):
    farm.patch({"level": 40, "barn.items": {"egg": 500, "butter": 100}})
    reload(game)
    assert game.locator(".outside.empty[data-outside=cafe]").count() == 1, "no café yet"

    open_tab(game, "build")
    game.get_by_role("tab", name="Extérieur").click()
    row(game, "Café-boutique").get_by_role("button", name="Construire").click()
    settle(game)
    game.wait_for_selector(".outside[data-outside=cafe][data-built='1']")
    close_sheet(game)

    open_tab(game, "cafe")
    assert "Clients du jour" in game.locator("#sheet-body").inner_text()
    card = game.locator("#sheet .recipe", has_text="Œufs brouillés")
    assert "☆☆☆☆☆☆☆" in card.inner_text()
    assert game.locator("#sheet .recipe", has_text="Mille-feuille").get_by_role("button", name="Café niv. 7").is_disabled()

    for _ in range(4):
        card.get_by_role("button", name="Servir").click()
        settle(game)

    state = farm.state()
    assert state["barn"]["items"]["egg"] == 500 - 4 * 40
    assert state["cafe"]["served"] == {"scrambled_eggs": 4}
    assert state["cafe"]["rewarded"] >= 1, "4 x 60+ points pass the 190 milestone"
    assert "★☆☆☆☆☆☆" in card.inner_text(), "first star after 3 serves"
    assert "✓ 190" in game.locator("#sheet .milestones").inner_text()
