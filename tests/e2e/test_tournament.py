"""Solo tournament: league, ranking, end of the week, admin curve."""
from conftest import close_sheet, open_tab, reload, settle, text


def test_league_and_ranking(game, farm):
    open_tab(game, "tournament")
    body = text(game.locator("#sheet-body"))
    assert "Bronze 1★" in body
    assert game.locator(".rank-row").count() == 20
    assert game.locator(".rank-row.me").count() == 1
    assert game.locator(".rank-row.zone-relegated").count() == 5


def test_end_of_week_promotes_and_pays(game, farm):
    farm.patch({"market_xp": 2400})
    reload(game)
    open_tab(game, "tournament")
    assert text(game.locator(".rank-row").first).startswith("1")
    assert "⭐ Ma ferme" in text(game.locator(".rank-row").first)
    close_sheet(game)

    open_tab(game, "admin")
    game.get_by_role("button", name="Terminer le tournoi maintenant").click()
    settle(game)
    state = farm.state()
    assert state["tournament"]["tier"] == 1
    assert state["materials"]["keys"] == 10
    assert state["barn"]["items"]["rose_seed"] == 5
    close_sheet(game)

    open_tab(game, "tournament")
    body = text(game.locator("#sheet-body"))
    assert "Bronze 2★" in body
    assert "Tournoi précédent : 1ᵉʳ en Bronze 1★" in body
    assert "montée en Bronze 2★" in body


def test_admin_curve(game, farm):
    open_tab(game, "admin")
    game.select_option("select[name=first-objective]", "4")
    game.select_option("select[name=first-percent]", "10")
    game.get_by_role("button", name="Enregistrer la courbe").click()
    settle(game)

    curve = {row["group"]: row for row in farm.state()["settings"]["tournament_curve"]}
    assert curve["first"] == {"group": "first", "objective": 4, "percent": 10}
    assert curve["middle"] == {"group": "middle", "objective": 3, "percent": 40}
