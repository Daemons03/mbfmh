"""Banners above buildings: idle (zZ), ready, "tout récolter" above the barn, show animals; no timers."""
import time

from conftest import reload, settle


def plant_done(farm, crops):
    """Fields b2 and b3 planted long ago with the given crops."""
    state = farm.state()
    for building, crop in zip([b for b in state["buildings"] if b["type"] == "field"], crops):
        building["job"] = {"product": crop, "started_at": int(time.time()) - 99_999, "duration": 60, "outputs": {crop: 10}}
    farm.patch({"buildings": state["buildings"]})


def test_idle_fields_show_a_sleeping_banner(game):
    assert game.locator(".tags[data-id] .pennant.idle").count() == 2, "two empty fields"
    assert game.locator("[data-collect-all]").count() == 0


def test_collect_all_from_the_barn(game, farm):
    plant_done(farm, ["corn", "wildflower"])
    reload(game)
    assert game.locator(".tags[data-id] .pennant.ready").count() == 2
    banner = game.locator("[data-collect-all]")
    assert banner.get_attribute("title") == "Tout récolter (2)"

    banner.click()
    settle(game)

    state = farm.state()
    assert state["barn"]["items"] == {"corn": 10, "wildflower": 10}
    assert farm.log() == ["collect_all"]
    assert game.locator("[data-collect-all]").count() == 0
    assert game.locator(".tags[data-id] .pennant.idle").count() == 2


def test_one_ready_job_has_no_collect_all(game, farm):
    plant_done(farm, ["corn"])
    reload(game)
    assert game.locator(".tags[data-id] .pennant.ready").count() == 1
    assert game.locator("[data-collect-all]").count() == 0


def test_outside_lots_open_their_panel(game, farm):
    assert game.locator(".outside").count() == 6, "market, show animals, library, external barn, café, sewing workshop"
    assert game.locator(".outside.empty[data-outside=library]").count() == 1

    game.locator(".outside[data-outside=library]").click()
    game.wait_for_selector("#sheet:not([hidden])")
    assert game.locator("#sheet-title").inner_text() == "Construire"
    game.keyboard.press("Escape")

    farm.patch({"buildings": farm.state()["buildings"] + [{"id": "lib", "type": "library", "level": 1}]})
    reload(game)
    game.locator(".outside[data-outside=library]").click()
    game.wait_for_selector("#sheet:not([hidden])")
    assert game.locator("#sheet-title").inner_text() == "📚 Bibliothèque"


def test_no_timer_above_a_working_field(game, farm):
    state = farm.state()
    field = next(b for b in state["buildings"] if b["type"] == "field")
    field["job"] = {"product": "corn", "started_at": int(time.time()), "duration": 3, "outputs": {"corn": 10}}
    farm.patch({"buildings": state["buildings"]})
    reload(game)

    tag = game.locator(f".tags[data-id={field['id']}]")
    assert tag.locator(".pennant").count() == 0 and not tag.inner_text().strip(), "nothing shown while it grows"
    tag.locator(".pennant.ready").wait_for(timeout=6_000)  # the map still updates once ready


def show_banner(game):
    return game.locator(".tags[data-outside=show_animal]")


def test_show_animals_banner(game, farm):
    # Starting farm: one free slot, no animal working.
    assert show_banner(game).locator(".pennant.idle").count() == 1

    job = {"animal": "chicken_grey_1", "started_at": int(time.time()), "duration": 3, "outputs": {"egg": 10}}
    farm.patch({"show.animals": {"chicken_grey_1": {"level": 1, "cards": 1}}, "show.active": {"chicken": job}})
    reload(game)
    assert show_banner(game).locator(".pennant").count() == 0, "every slot busy, not ready: nothing"

    show_banner(game).locator(".pennant.ready").wait_for(timeout=6_000)
    show_banner(game).click()
    game.wait_for_selector("#sheet:not([hidden])")
    assert "concours" in game.locator("#sheet-title").inner_text().lower()
