"""Show animals: chests, cards, free activation, one fixed duration per animal."""
import json
import time
from pathlib import Path

from conftest import close_sheet, open_tab, reload, settle

ANIMALS = json.loads((Path(__file__).parents[2] / "data/game-data.json").read_text())["show_animals"]["animals"]
DATA_NAMES = {id_: a["name"] for id_, a in ANIMALS.items()}


def test_chests_and_activation(game, farm):
    farm.patch({"materials.tokens": 100, "settings.speed": 100,
                "show": {"seed": "e2e", "opened": 0, "token_day": "2000-01-01", "animals": {}, "active": {}}})
    reload(game)
    open_tab(game, "show")
    body = game.locator("#sheet-body")
    assert "🎟️" in body.inner_text()

    game.get_by_role("button", name="Ouvrir 11 coffres (100 🎟️)").click()
    settle(game)
    state = farm.state()
    assert state["materials"]["tokens"] == 20, "100 spent, +20 daily on the reload"
    assert "🎉" in body.inner_text()
    draw = state["show"]["last_draw"]
    if "animal" not in draw:
        return  # blueprints this time (0.4 %): nothing to activate

    owned = state["show"]["animals"][draw["animal"]]
    assert owned["level"] >= 1
    detail = game.locator(".item-detail")  # the drawn animal is shown
    assert detail.locator(".row-title").inner_text().startswith(DATA_NAMES[draw["animal"]])
    detail.get_by_role("button", name="Lancer").click()
    game.wait_for_selector("#sheet", state="hidden")  # the panel closes once started
    assert len(farm.state()["show"]["active"]) == 1
    open_tab(game, "show")
    assert "Animaux au travail : 1 / 1" in body.inner_text()
    close_sheet(game)


def test_cancel_a_working_show_animal(game, farm):
    job = {"animal": "chicken_grey_1", "started_at": int(time.time()), "duration": 3600, "outputs": {"egg": 10}}
    farm.patch({"show.animals": {"chicken_grey_1": {"level": 1, "cards": 1}}, "show.active": {"chicken": job}})
    reload(game)
    open_tab(game, "show")
    game.get_by_role("button", name="Annuler").click()  # confirm accepted by the page fixture
    settle(game)

    state = farm.state()
    assert state["show"]["active"] == {}
    assert state["barn"]["items"] == {}
    assert farm.log() == ["cancel_show_animal"]


def test_each_animal_has_its_own_duration(game, farm):
    owned = {f"chicken_grey_{i}": {"level": 1, "cards": 0} for i in (1, 2, 3)}
    farm.patch({"show.animals": owned, "show.active": {}})
    reload(game)
    open_tab(game, "show")

    tiles = game.locator(".tiles.animals").first.locator(".tile")
    assert [t.inner_text().split("\n")[1] for t in tiles.all()] == ["Grisette", "Picorette", "Cendrine"]
    assert [t.locator(".tile-time").inner_text() for t in tiles.all()] == ["⏱ 6 min 30 s", "⏱ 20 min", "⏱ 3 h 30 min"]

    game.locator(".tile[title=Picorette]").click()
    game.locator(".item-detail").get_by_role("button", name="Lancer").click()
    game.wait_for_selector("#sheet", state="hidden")
    job = farm.state()["show"]["active"]["chicken"]
    assert (job["animal"], job["duration"], job["outputs"]) == ("chicken_grey_2", 1200, {"egg": 30})
