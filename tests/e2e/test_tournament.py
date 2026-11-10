"""Tournament: one league per tier (bots and real farms), lots, end of the week, admin curve."""
from conftest import accept_modal, close_sheet, open_tab, reload, settle, text


def test_league_and_ranking(game, farm):
    open_tab(game, "tournament")
    body = text(game.locator("#sheet-body"))
    assert "Bronze 1★" in body
    assert game.locator(".rank-row").count() == 1001, "the 1 000 bots of bronze 1★ and my farm"
    assert game.locator(".rank-row.me").count() == 1
    lots = [text(lot) for lot in game.locator(".rank-lot").all()]
    assert lots == ["Lot A · montée", "Lot B · montée", "Lot C · maintien", "Lot D · maintien"], "nothing below bronze 1★"
    assert game.locator(".rank-row.zone-first").count() == 20
    assert game.locator(".rank-row.zone-relegated").count() == 1001 - 150
    assert text(game.locator(".rank-row").last).startswith("1 001\n🎁"), "the last place's prize"
    assert "100 000 000" in text(game.locator(".last-prize")) and "Loser of the week" in text(game.locator(".last-prize"))


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
    assert "Tournoi précédent : 1ᵉʳ / 1 001 en Bronze 1★ · Lot A" in body
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


def test_real_farms_share_the_league_of_their_tier(game, farm):
    from conftest import Farm
    other = Farm("e2e-playwright-2")
    other.reset()
    try:
        other.patch({"market_xp": 50_000, "autoplay": {"activity": 0.95}})
        reload(game)
        open_tab(game, "tournament")
        first = game.locator(".rank-row").first
        assert "🤖 e2e-playwright-2" in text(first), "the other farm leads my league"
        assert "95" in text(first.locator(".bot-title"))
        first.click()
        game.locator("#visit-banner").wait_for()

        rows = other._run("view", other.login)["tournament"]["ranking"]
        assert any(row["name"] == farm.login and row["player"] for row in rows), "and I am in its league"
    finally:
        other.delete()


def test_live_ranking_of_every_league(game, farm):
    from conftest import Farm
    other = Farm("e2e-playwright-2")
    other.reset()
    try:
        other.patch({"tournament.tier": 3, "market_xp": 700})
        open_tab(game, "tournament")
        game.get_by_role("tab", name="📋 Ligues").click()
        picker = game.get_by_label("Palier", exact=True)
        picker.wait_for()
        assert "Bronze 1★ · 1 001 fermes · ma ligue" in text(picker.locator("option:checked"))
        assert game.locator(".rank-row.me").count() == 1

        picker.focus()  # as after a tap: a focused select must not keep the list from updating
        picker.select_option(label="🥈 Argent 1★ · 1 ferme")
        row = game.locator(".rank-row.player")
        row.wait_for()
        assert "👤 e2e-playwright-2" in text(row) and "700" in text(row)
        assert "Lot A · montée" in text(game.locator(".rank-lot").first)

        picker.select_option(label="🥈 Argent 2★ · 0 ferme")
        game.get_by_text("Personne dans ce palier cette semaine.").wait_for()
    finally:
        other.delete()
