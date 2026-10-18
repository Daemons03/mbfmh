"""Step 6: login, grid, plant, harvest, sell, build, move."""
from conftest import cell_center, PASSWORD, cash_text, close_sheet, grid_side, login, open_tab, reload, row, settle, tap


def test_wrong_password_then_login(page, farm, base_url):
    login(page, base_url, password="wrong-password")
    page.wait_for_selector("#login-error:not([hidden])")
    assert page.locator("#login-error").inner_text() == "Identifiant ou mot de passe incorrect."

    page.fill("[name=password]", PASSWORD)
    page.click("button[type=submit]")
    page.wait_for_selector("#grid .building")
    assert page.locator(".level-badge").inner_text() == "Niv. 1"
    assert "2 000" in cash_text(page)


def test_starting_farm(game):
    assert grid_side(game) == 12
    assert game.locator("#grid .cell.locked").count() == 48
    assert game.locator("#grid .building").count() == 4, "barn, farmhouse, two fields"
    assert "null" not in game.locator("#topbar").inner_text()


def test_plant_countdown_and_one_tap_harvest(game, farm):
    tap(game, 3, 0)
    game.wait_for_selector("#sheet:not([hidden])")
    assert game.locator("#sheet-title").inner_text() == "🟫 Champ — niv. 1"
    assert row(game, "Chou").get_by_role("button").inner_text() == "Niv. 8"

    row(game, "Maïs").get_by_role("button", name="Planter").click()
    countdown = game.locator("#sheet [data-ready-at]")
    countdown.wait_for()
    first = countdown.inner_text()
    game.wait_for_timeout(2100)
    assert countdown.inner_text() != first, "countdown ticks every second"
    assert "1 984" in cash_text(game), "corn 43 x 0.374 (fence happiness 110)"
    close_sheet(game)

    # Another device speeds the farm up: the next action gets a 409 and reloads.
    farm.patch({"settings.speed": 100})
    tap(game, 3, 2)  # second field b3 at (2,2)
    game.wait_for_selector("#sheet:not([hidden])")
    row(game, "Fleurs sauvages").get_by_role("button", name="Planter").click()
    game.wait_for_selector(".toast.warn >> text=modifiée ailleurs")
    close_sheet(game)

    game.wait_for_selector(".building.field.ready", timeout=10_000)
    tap(game, 3, 0)
    settle(game)
    assert farm.state()["barn"]["items"] == {"corn": 10}
    assert game.locator(".xp-text").inner_text().startswith("1 /")


def test_sell_from_barn(game, farm):
    farm.patch({"barn.items": {"corn": 10}})
    reload(game)
    open_tab(game, "barn")
    corn = row(game, "Maïs")
    assert "10 / 200" in corn.inner_text()

    corn.get_by_role("button", name="Tout").click()
    settle(game)
    assert "2 050" in cash_text(game)
    assert farm.state()["barn"]["items"] == {}
    assert "La grange est vide" in game.locator("#sheet-body").inner_text()


def test_build_with_placement_mode_and_build_here(game, farm):
    farm.add_house(10, 0)
    farm.add_house(10, 2)  # 2 + 20 free workers for two fields
    reload(game)
    open_tab(game, "build")
    row(game, "Champ").get_by_role("button", name="Placer").click()
    assert "Touchez une case" in game.locator("#mode-banner").inner_text()
    tap(game, 6, 5)
    game.wait_for_selector("#mode-banner[hidden]", state="attached")

    tap(game, 6, 2)
    game.wait_for_selector("#sheet:not([hidden])")
    assert game.locator("#sheet-title").inner_text() == "Construire ici"
    row(game, "Champ").get_by_role("button", name="Construire").click()
    settle(game)

    fields = [(b["x"], b["y"]) for b in farm.state()["buildings"] if b["type"] == "field"]
    assert (6, 5) in fields and (6, 2) in fields
    assert farm.state()["cash"] == 2000 - 2 * 250


def test_touch_placement_needs_confirmation(game, farm):
    farm.add_house(10, 0)
    reload(game)
    open_tab(game, "build")
    row(game, "Champ").get_by_role("button", name="Placer").click()

    game.touchscreen.tap(*cell_center(game, 6, 5))
    assert "Champ ici ?" in game.locator("#mode-banner").inner_text()
    assert game.locator("#ghost").get_attribute("points") != "0,0", "footprint shown"
    assert not [b for b in farm.state()["buildings"] if (b.get("x"), b.get("y")) == (6, 5)], "nothing built yet"

    game.locator("#mode-banner").get_by_role("button", name="Valider").click()
    game.wait_for_selector("#mode-banner[hidden]", state="attached")
    assert any((b.get("x"), b.get("y")) == (6, 5) for b in farm.state()["buildings"] if b["type"] == "field")


def test_rotate_when_placing_and_in_place(game, farm):
    farm.add_house(10, 0)
    reload(game)
    open_tab(game, "build")
    row(game, "Champ").get_by_role("button", name="Placer").click()
    game.locator("#mode-banner").get_by_role("button", name="Pivoter").click()
    tap(game, 6, 0)
    game.wait_for_selector("#mode-banner[hidden]", state="attached")

    field = next(b for b in farm.state()["buildings"] if (b.get("x"), b.get("y")) == (6, 0))
    assert field.get("rotated") is True, "2 x 3 instead of 3 x 2"
    assert game.locator(f".building[data-id={field['id']}] svg.mirrored").count() == 1

    tap(game, 6, 2)  # third row only exists once rotated
    game.wait_for_selector("#sheet:not([hidden])")
    assert "2×3" in game.locator("#sheet .hero").inner_text()
    game.get_by_role("button", name="Pivoter").click()
    settle(game)
    assert "rotated" not in next(b for b in farm.state()["buildings"] if b["id"] == field["id"])
    assert farm.log() == ["build", "rotate"]


def test_move_barn(game, farm):
    tap(game, 0, 0)
    game.wait_for_selector("#sheet:not([hidden])")
    game.get_by_role("button", name="Déplacer").click()
    tap(game, 0, 4)
    settle(game)

    barn = next(b for b in farm.state()["buildings"] if b["type"] == "barn")
    assert (barn["x"], barn["y"]) == (0, 4)
    assert farm.log() == ["move"]


def test_locked_cell_explains(game):
    tap(game, 1, 9)
    game.wait_for_selector(".toast.warn")
    assert "terrain marqué 🔒" in game.locator(".toast.warn").last.inner_text()


def test_zoom_is_remembered(game):
    game.click("#zoom-out")
    game.click("#zoom-out")
    assert game.evaluate("() => getComputedStyle(document.documentElement).getPropertyValue('--cell')") == "28px"
    reload(game)
    assert game.evaluate("() => getComputedStyle(document.documentElement).getPropertyValue('--cell')") == "28px"


def test_small_screen_and_dark_mode(make_page, farm, base_url):
    for width, height, scheme in ((360, 740, "light"), (360, 740, "dark"), (1280, 800, "dark")):
        page = make_page(width, height, scheme)
        login(page, base_url)
        assert page.evaluate("() => document.documentElement.scrollWidth <= window.innerWidth"), (width, scheme)
