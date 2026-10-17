"""Step 10: admin tab and admin.html."""
from conftest import close_sheet, grid_side, open_tab, settle, tap


def test_finish_jobs_and_speed(game, farm):
    tap(game, 3, 0)
    game.wait_for_selector("#sheet:not([hidden])")
    game.locator(".row", has_text="Maïs").get_by_role("button", name="Planter").click()
    close_sheet(game)

    open_tab(game, "admin")
    game.get_by_role("button", name="Tout terminer maintenant").click()
    settle(game)
    close_sheet(game)
    assert game.locator(".building.ready").count() == 1

    open_tab(game, "admin")
    game.get_by_role("button", name="×100", exact=True).click()
    settle(game)
    assert farm.state()["settings"]["speed"] == 100
    assert farm.log() == ["plant", "admin:finish_all_jobs", "admin:set_speed"]


def test_forms_and_typing_survives_sync(game, farm):
    open_tab(game, "admin")
    cash = game.locator("input[name=cash]")
    cash.fill("123456")
    game.evaluate("() => document.dispatchEvent(new Event('visibilitychange'))")  # triggers a sync
    game.wait_for_timeout(800)
    assert cash.input_value() == "123456", "a sync must not wipe what is being typed"

    game.locator("input[name=nails]").fill("9")
    game.get_by_role("button", name="Enregistrer les ressources").click()
    settle(game)
    game.locator(".admin-section", has_text="Grange").locator("input[name=corn]").fill("500")
    game.get_by_role("button", name="Enregistrer la grange").click()
    settle(game)

    state = farm.state()
    assert (state["cash"], state["materials"]["nails"]) == (123_456, 9)
    assert state["barn"]["items"] == {"corn": 500}, "admin may exceed capacity"
    assert "📌 9" in game.locator("#topbar").inner_text()


def test_level_unlock_and_reset(game, farm):
    open_tab(game, "admin")
    level = game.locator("input[name=level]")
    apply = game.locator(".admin-section", has_text="Niveau").get_by_role("button", name="Appliquer")

    level.fill("0")
    apply.click()
    settle(game)
    assert level.evaluate("e => !e.checkValidity()")
    assert farm.state()["level"] == 1

    level.fill("20")
    apply.click()
    settle(game)
    assert game.locator(".level-badge").inner_text() == "Niv. 20"

    game.get_by_role("button", name="Tout débloquer").click()
    settle(game)
    assert game.locator(".level-badge").inner_text() == "Niv. 200"
    assert grid_side(game) == 32

    game.get_by_role("button", name="Réinitialiser la ferme").click()  # prompt answered "RESET"
    settle(game)
    state = farm.state()
    assert (state["level"], state["cash"], state["expansions"]) == (1, 2000, 6)


def test_admin_page(game, make_page, base_url, farm):
    # Same browser context as the game: shares the session cookie.
    admin = game.context.new_page()
    admin.goto(base_url + "admin.html")
    admin.wait_for_selector("#admin-body .admin-section")
    assert "Niveau 1" in admin.locator("#admin-summary").inner_text()

    admin.get_by_role("button", name="×10", exact=True).click()
    admin.wait_for_function("() => document.querySelector('#admin-summary').innerText.includes('×10')")
    admin.close()

    # The game tab is now one version behind: its next request reloads.
    open_tab(game, "admin")
    game.get_by_role("button", name="×1", exact=True).click()
    game.wait_for_selector(".toast.warn >> text=modifiée ailleurs")
    assert farm.state()["settings"]["speed"] == 10


def test_admin_page_requires_login(make_page, base_url, farm):
    anonymous = make_page()
    anonymous.goto(base_url + "admin.html")
    anonymous.wait_for_url(base_url)
    anonymous.wait_for_selector("#login:not([hidden])")
