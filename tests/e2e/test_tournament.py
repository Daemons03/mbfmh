"""Solo tournament: league, ranking, end of the week, admin curve."""
from conftest import accept_modal, close_sheet, open_tab, reload, settle, text


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
    accept_modal(game)
    settle(game)
    state = farm.state()
    assert state["tournament"]["tier"] == 1
    assert state["materials"]["keys"] == 15, "1st place 10 + objective 6: 5"
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


def test_objective_rewards(game, farm):
    farm.patch({"barn.items": {"tulip_seed": 1}})
    reload(game)
    from conftest import tap, row
    tap(game, 3, 0)
    game.wait_for_selector("#sheet:not([hidden])")
    row(game, "Tulipes").get_by_role("button", name="Planter").click()  # +450 points
    settle(game)
    close_sheet(game)

    open_tab(game, "tournament")
    assert game.locator(".objective-reward").count() == 6
    assert game.locator(".objective-reward.done").count() == 2
    assert farm.state()["barn"]["items"] == {"instant_feed": 1, "tulip_seed": 1}
