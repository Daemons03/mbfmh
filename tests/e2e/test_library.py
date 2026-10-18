"""Library (product upgrades) and market upgrades."""
from conftest import close_sheet, open_tab, reload, row, settle, text


def test_library_upgrade_adds_to_the_harvest(game, farm):
    farm.patch({"level": 15, "materials.scrolls": 100, "materials.ink": 100, "materials.books": 100})
    reload(game)
    assert game.locator(".outside.empty[data-outside=library]").count() == 1, "no library yet"

    open_tab(game, "build")
    row(game, "Bibliothèque").get_by_role("button", name="Construire").click()
    settle(game)
    game.wait_for_selector(".outside[data-outside=library][data-built='1']")
    close_sheet(game)

    open_tab(game, "library")
    corn = row(game, "Maïs — niv. 0 / 20")
    assert "niv. 1 : +1 pour 20 📜 · 20 🖋️ · 5 📕" in text(corn)
    corn.get_by_role("button", name="Améliorer").click()
    settle(game)
    corn = row(game, "Maïs — niv. 1 / 20")
    assert "+1 🌽 par récolte" in text(corn)
    assert corn.get_by_role("button", name="Biblio niv. 2").is_disabled(), "capped by the library level"
    close_sheet(game)

    state = farm.state()
    assert state["library"] == {"corn": 1}
    assert state["materials"]["scrolls"] == 80
    # Planting shows and gives the bonus: 10 corn on a level 1 field + 1.
    field = next(b for b in state["buildings"] if b["type"] == "field")
    game.locator(f".building[data-id={field['id']}]").click()
    assert "11 🌽" in text(row(game, "Maïs"))


def test_market_upgrade_adds_a_contract_and_a_bonus(game, farm):
    farm.patch({"materials.bricks": 30, "materials.tiles": 30, "barn.items": {"wildflower": 200, "corn": 200}})
    reload(game)
    open_tab(game, "market")
    assert game.locator(".contract").count() == 6

    row(game, "Contrats — niv. 0 / 6").get_by_role("button", name="Améliorer").click()
    settle(game)
    assert game.locator(".contract").count() == 7
    row(game, "Argent — niv. 0 / 60").get_by_role("button", name="Améliorer").click()
    settle(game)
    assert "+5 💵 par contrat" in text(row(game, "Argent — niv. 1 / 60"))

    contract = farm.state()["contracts"][0]
    expected = f"{contract['reward']['cash'] + 5:,}".replace(",", " ")
    assert f"+{expected} 💵" in text(game.locator(".contract").first)
    game.locator(".contract").first.get_by_role("button", name="Livrer").click()
    settle(game)

    state = farm.state()
    assert state["market_upgrades"] == {"contracts": 1, "cash": 1}
    assert state["cash"] == 2000 + contract["reward"]["cash"] + 5
