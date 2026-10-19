"""Bots: titles in the league, world ranking, bot profile, admin."""
import pytest

from conftest import close_sheet, open_tab, reload, settle, text


@pytest.fixture(autouse=True)
def fresh_bots(farm):
    farm.reset_bots()  # every test starts (and leaves) a new world at level 1
    yield
    farm.reset_bots()


def test_league_of_real_bots_with_titles(game):
    open_tab(game, "tournament")
    rows = game.locator(".rank-row")
    assert rows.count() == 20
    assert game.locator(".rank-row .bot-title").count() == 19, "every bot shows its profile"
    titles = {t.inner_text() for t in game.locator(".rank-row .bot-title").all()}
    assert titles <= {"90", "70", "60", "50"}

    game.locator(".rank-row.clickable").first.click()
    game.locator("#sheet-body .card .status").wait_for()
    assert "Niveau" in text(game.locator("#sheet-body .card"))
    assert game.locator("#sheet-body .list .row").count() >= 3, "barn, farmhouse, fields"
    game.get_by_role("button", name="← Retour").click()
    game.get_by_role("tab", name="🏅 Ma ligue").wait_for()


def test_world_ranking_sorts_and_filters(game):
    open_tab(game, "tournament")
    game.get_by_role("tab", name="🌍 Classement").click()
    game.locator(".rank-row.world").first.wait_for()
    assert "1 000 fermes" in text(game.locator("#sheet-body"))
    top = game.locator(".rank-row.world").evaluate_all("rows => rows.filter(r => !r.previousElementSibling || !r.matches('.rank-gap ~ .rank-row')).length")
    assert top == 100, "the top 100 before my place"

    game.get_by_role("button", name="Autour de moi").click()
    assert game.locator(".rank-row.world.me").count() == 1

    game.get_by_role("button", name="90", exact=True).click()
    titles = {t.inner_text() for t in game.locator(".rank-row.world:not(.me) .bot-title").all()}
    assert titles == {"90"}

    game.get_by_role("button", name="Top 100").click()
    game.get_by_role("button", name="Marché").click()
    first, second = game.locator(".rank-row.world .rank-stats").nth(0), game.locator(".rank-row.world .rank-stats").nth(1)
    level = lambda row: int(text(row).split("🏪 ")[1].replace(" ", ""))
    assert level(first) >= level(second)


def test_admin_moves_the_bots_forward_and_sets_their_speed(game, farm):
    open_tab(game, "tournament")
    game.get_by_role("tab", name="🌍 Classement").click()
    game.locator(".rank-row.world").first.wait_for()
    before = text(game.locator(".rank-row.world .rank-stats").first)
    close_sheet(game)

    open_tab(game, "admin")
    game.locator("input[name=bot_days]").fill("3")
    game.get_by_role("button", name="Avancer").click()
    game.wait_for_selector(".toast >> text=Bots modifiés")
    game.get_by_role("button", name="🤖 ×2").click()
    game.wait_for_selector(".admin-section >> text=×2")
    assert farm.log()[-2:] == ["admin:advance_bots", "admin:set_bot_speed"]
    close_sheet(game)

    open_tab(game, "tournament")
    game.get_by_role("tab", name="🌍 Classement").click()
    game.locator(".rank-row.world").first.wait_for()
    assert text(game.locator(".rank-row.world .rank-stats").first) != before, "3 more days played"
    assert "bots ×2" in text(game.locator("#sheet-body"))


def test_my_place_outside_the_top_100(game, farm):
    open_tab(game, "tournament")
    game.get_by_role("tab", name="🌍 Classement").click()
    game.locator(".rank-row.world").first.wait_for()
    body = text(game.locator("#sheet-body"))
    place = int(body.split("ma place : ")[1].split("ᵉ")[0].replace(" ", ""))
    assert place > 100, "a new farm at level 1 behind 1 000 bots"

    gap = game.locator(".rank-gap")
    assert f"ma place est {place:,}ᵉ".replace(",", " ") in text(gap)
    me = game.locator(".rank-row.world.me")
    assert text(me.locator(".rank-number")) == f"{place:,}".replace(",", " ")
    assert game.locator(".rank-gap ~ .rank-row").count() >= 3, "my place with its neighbours"
