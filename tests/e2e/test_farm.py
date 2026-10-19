"""Step 6: login, grid, plant, harvest, sell, build, move."""
import re

from conftest import cell_center, PASSWORD, cash_text, close_sheet, grid_side, login, open_tab, reload, row, settle, tap, text


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
    assert game.locator("#sheet-title").inner_text() == "🟫 Champ\nNiveau 1 / 18"
    assert row(game, "Chou").get_by_role("button").inner_text() == "Niv. 8"

    row(game, "Maïs").get_by_role("button", name="Planter").click()
    game.wait_for_selector("#sheet", state="hidden")  # the panel closes once planted
    tap(game, 3, 0)
    countdown = game.locator("#sheet [data-ready-at][data-format=long]")
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
    detail = game.locator(".item-detail")
    assert "Maïs" in detail.inner_text(), "first item in stock is chosen"
    assert "10 / 200" in text(detail)

    detail.get_by_role("button", name="Vendre").click()  # all of it by default
    settle(game)
    assert "2 050" in cash_text(game)
    assert farm.state()["barn"]["items"] == {}
    assert "aucun en stock" in detail.inner_text()
    assert game.locator(".tile[title='Maïs'] .tile-qty").inner_text() == "0", "unlocked crops stay listed"


def test_sell_a_chosen_quantity_with_the_slider(game, farm):
    farm.patch({"barn.items": {"corn": 10, "wildflower": 3}})
    reload(game)
    open_tab(game, "barn")
    game.locator(".tile[title='Maïs']").click()
    detail = game.locator(".item-detail")

    detail.locator("input[type=range]").fill("4")
    assert "🌽 4" in text(detail.locator(".sale")) and "20" in text(detail.locator(".sale"))
    detail.get_by_role("button", name="Vendre").click()
    settle(game)
    assert farm.state()["barn"]["items"] == {"corn": 6, "wildflower": 3}
    assert "2 020" in cash_text(game)
    assert detail.locator("input[type=range]").get_attribute("max") == "6"


def test_barn_tabs_sort_products_and_resources(game, farm):
    farm.patch({"level": 20, "barn.items": {"egg": 5, "corn": 2, "dung": 7, "humus": 1, "rose_seed": 1, "wheat": 4}})
    reload(game)
    open_tab(game, "barn")

    titles = game.locator(".tiles.products .tile").evaluate_all("els => els.map(e => e.title)")
    assert titles[:5] == ["Fleurs sauvages", "Maïs", "Chou", "Blé", "Pissenlit"], "fields first, by unlock level"
    assert "Œuf" in titles and "Fumier" not in titles
    assert [t.inner_text() for t in game.locator("#sheet-body .section-title").all()][:2] == ["Champs", "Animaux"]

    game.get_by_role("tab", name="Ressources").click()
    resources = game.locator(".tiles.resources .tile").evaluate_all("els => els.map(e => e.title)")
    assert {"Fumier", "Humus", "Graines de roses"} <= set(resources)
    assert "Œuf" not in resources


def ready_field(farm, rotated=False, outputs=None):
    """Starter field b2 at (2, 0) ready to harvest corn; b3 moved away so b2 can turn to 2 x 3."""
    buildings = farm.state()["buildings"]
    for b in buildings:
        if b["id"] == "b3":
            b["x"], b["y"] = 5, 0
        if b["id"] == "b2":
            b["job"] = {"product": "corn", "started_at": 1, "duration": 1, "outputs": outputs or {"corn": 10}, "xp": 1}
            if rotated:
                b["rotated"] = True
    farm.patch({"buildings": buildings})


def test_harvest_offers_to_sell_what_does_not_fit(game, farm):
    ready_field(farm)
    farm.patch({"barn.items": {"corn": 195}})
    reload(game)

    tap(game, 3, 0)
    modal = game.locator(".modal")
    modal.wait_for()
    assert "195 en grange + 10 récoltés, 5 ne rentrent pas" in text(modal)
    assert "+25" in text(modal.locator(".status")), "the minimum by default: 5 at 5"
    modal.get_by_role("button", name="Vendre et récolter").click()
    settle(game)

    state = farm.state()
    assert state["barn"]["items"] == {"corn": 200}
    assert state["cash"] == 2000 + 25
    assert "job" not in next(b for b in state["buildings"] if b["id"] == "b2")


def test_harvest_sells_more_with_the_slider_or_cancels(game, farm):
    ready_field(farm)
    farm.patch({"barn.items": {"corn": 195}})
    reload(game)

    tap(game, 3, 0)
    game.locator(".modal").wait_for()
    game.get_by_role("button", name="Annuler").click()
    game.locator(".modal").wait_for(state="detached")
    assert farm.state()["barn"]["items"] == {"corn": 195}, "nothing sold, the crop waits"

    tap(game, 3, 0)
    modal = game.locator(".modal")
    slider = modal.locator("input[type=range]")
    assert (slider.get_attribute("min"), slider.get_attribute("max")) == ("5", "205")
    slider.fill("105")
    assert "il en restera 100 / 200 en grange" in text(modal)
    modal.get_by_role("button", name="Vendre et récolter").click()
    settle(game)
    state = farm.state()
    assert state["barn"]["items"] == {"corn": 100}
    assert state["cash"] == 2000 + 105 * 5


def test_crops_follow_a_rotated_field(game, farm):
    ready_field(farm, rotated=True)
    reload(game)
    # Every crop drawn on the picture sits on the 2 x 3 diamond of the turned field.
    outside = game.evaluate("""() => {
        const svg = [...document.querySelectorAll('.building[data-id=b2] svg:not(.hit)')].find((s) => s.querySelector('text'));
        const [, , vw, vh] = svg.getAttribute('viewBox').split(' ').map(Number);
        const [w, h] = [2, 3], top = vh - (w + h) / 2;
        const poly = [[0, top + h / 2], [h, top], [w + h, top + w / 2], [w, top + (w + h) / 2]];
        const inside = ([x, y]) => poly.every(([ax, ay], i) => {
            const [bx, by] = poly[(i + 1) % 4];
            return (bx - ax) * (y - ay) - (by - ay) * (x - ax) >= -0.01;
        });
        return [...svg.querySelectorAll('text')]
            .map((t) => [Number(t.getAttribute('x')), Number(t.getAttribute('y'))])
            .filter((p) => !inside(p)).length + (vw === w + h ? 0 : 100);
    }""")
    assert outside == 0


def test_cancel_then_speed_up_a_crop(game, farm):
    tap(game, 3, 0)
    row(game, "Maïs").get_by_role("button", name="Planter").click()
    game.wait_for_selector("#sheet", state="hidden")
    cash = farm.state()["cash"]

    tap(game, 3, 0)
    game.get_by_role("button", name="Annuler").click()  # confirm accepted by the page fixture
    row(game, "Maïs").wait_for()  # the field can be planted again
    state = farm.state()
    assert "job" not in next(b for b in state["buildings"] if b["id"] == "b2")
    assert state["cash"] == cash, "nothing refunded"

    row(game, "Maïs").get_by_role("button", name="Planter").click()
    game.wait_for_selector("#sheet", state="hidden")
    tap(game, 3, 0)
    speed = game.get_by_role("button", name=re.compile("Accélérer"))
    assert re.search(r"\(20 ", speed.inner_text()), "corn: 2 min left, 10 gold a minute"
    speed.click()
    game.get_by_role("button", name=re.compile("^Récolter")).wait_for()
    assert farm.state()["gold"] == 50 - 20
    assert farm.log() == ["plant", "cancel_job", "plant", "speed_up"]


def test_plant_every_free_field_while_humus_lasts(game, farm):
    farm.patch({"barn.items": {"humus": 1}})
    reload(game)
    tap(game, 3, 0)
    game.locator("label", has_text="humus : +25 %").click()
    row(game, "Maïs").get_by_role("button", name="Tout (2)").click()
    game.wait_for_selector(".toast.warn >> text=1 champ reste libre")

    fields = {b["id"]: b for b in farm.state()["buildings"] if b["type"] == "field"}
    assert fields["b2"]["job"]["humus"] is True
    assert "job" not in fields["b3"], "no humus left for the second field"
    assert farm.log() == ["plant_all"]


def test_build_tabs_by_category(game, farm):
    open_tab(game, "build")
    names = lambda: [t.inner_text() for t in game.locator("#sheet .row-title").all()]
    assert "Champ" in names() and "Champ premium" not in names()
    game.get_by_role("tab", name="Premium").click()
    assert "Champ premium" in names() and "Champ fertile" in names() and "Champ" not in names()
    game.get_by_role("tab", name="Plein air").click()
    assert all("plein air" in n.lower() for n in names())
    game.get_by_role("tab", name="Extérieur").click()
    assert "Bibliothèque" in names()


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
    assert game.locator(f".building[data-id={field['id']}] .mirrored").count() == 1

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


def test_zoom_buttons_and_banner_stay_above_buildings(game, farm):
    farm.add_house(10, 0)  # workers for a field
    reload(game)
    while game.locator("#zoom-in").is_enabled():  # buildings fill the screen
        game.locator("#zoom-in").click()
    open_tab(game, "build")
    row(game, "Champ").get_by_role("button", name="Placer").click()
    game.locator(".building").first.scroll_into_view_if_needed()

    # Bring a building right under each control, then check what is drawn on top there
    # (pointer events back on: while placing, buildings ignore them and elementFromPoint too).
    on_top = game.evaluate("""() => {
        const probe = document.createElement('style');
        probe.textContent = '* { pointer-events: auto !important; }';
        document.head.append(probe);
        const map = document.querySelector('#map');
        const buildings = [...document.querySelectorAll('#grid .building')];
        const lowest = buildings.reduce((a, b) => (b.getBoundingClientRect().bottom > a.getBoundingClientRect().bottom ? b : a));
        const result = [['#zoom-in', lowest], ['#zoom-out', lowest], ['#mode-banner button', buildings[0]]].map(([sel, building]) => {
            const el = document.querySelector(sel);
            const r = el.getBoundingClientRect();
            const [x, y] = [r.x + r.width / 2, r.y + r.height / 2];
            const b = building.getBoundingClientRect();
            map.scrollLeft += (b.x + b.width / 2) - x;
            map.scrollTop += (b.y + b.height * 0.7) - y;
            const u = building.getBoundingClientRect();
            const covered = u.left < x && u.right > x && u.top < y && u.bottom > y;
            return covered && el.contains(document.elementFromPoint(x, y));
        });
        probe.remove();
        return result;
    }""")
    assert on_top == [True, True, True]


def test_level_folds_costs_and_production(game):
    tap(game, 3, 0)
    game.wait_for_selector("#sheet:not([hidden])")
    costs = game.locator("details.fold", has_text="Coût des niveaux suivants")
    production = game.locator("details.fold", has_text="Production par niveau")
    assert costs.get_attribute("open") is None, "folded by default"

    costs.locator("summary").click()
    assert costs.locator("tbody tr").count() == 17, "levels 2 to 18"
    assert costs.locator("tbody tr").first.inner_text().startswith("2")

    production.locator("summary").click()
    production.get_by_role("tab", name="Maïs").click()
    rows = production.locator("tbody tr")
    assert rows.count() == 18
    assert "10 🌽" in rows.first.inner_text() and "current" in rows.first.get_attribute("class")
    assert "27 🌽" in rows.last.inner_text()
    reload(game)
    tap(game, 3, 0)
    assert game.locator("details.fold[open]").count() == 0, "a new page starts folded"


def test_long_building_name_keeps_the_panel_whole(game, farm):
    buildings = farm.state()["buildings"] + [{"id": "d1", "type": "premium_duck_coop", "x": 6, "y": 4, "level": 1}]
    farm.patch({"buildings": buildings})
    reload(game)
    tap(game, 7, 5)
    game.wait_for_selector("#sheet:not([hidden])")
    assert game.locator("#sheet-title .title-sub").inner_text() == "Niveau 1 / 12"
    close = game.locator("#sheet-close").bounding_box()
    sheet = game.locator("#sheet").bounding_box()
    assert close["x"] + close["width"] <= sheet["x"] + sheet["width"], "close button stays inside"
    assert game.evaluate("document.querySelector('#sheet').scrollWidth <= document.querySelector('#sheet').clientWidth")
