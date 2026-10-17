"""Step 7: market contracts, and the per-product barn display."""
from conftest import cash_text, open_tab, reload, settle


def test_per_product_capacity_display(game, farm):
    farm.patch({"barn.items": {"wildflower": 200, "corn": 150}})
    reload(game)

    barn = game.locator(".wallet .res[title^=\"Grange\"]")
    assert barn.inner_text() == "🛖 200"
    assert barn.evaluate("e => e.style.color") == "var(--danger)", "a product is full"

    open_tab(game, "barn")
    body = game.locator("#sheet-body").inner_text()
    assert "200 par produit" in body
    assert "Plein : 🌼 Fleurs sauvages" in body
    assert "150 / 200" in body


def test_deliver_contract(game, farm):
    farm.patch({"barn.items": {"wildflower": 200, "corn": 200}})
    reload(game)
    assert game.locator("#tabs [data-tab=market] .tab-badge").inner_text() == "6"

    open_tab(game, "market")
    assert game.locator(".contract").count() == 6
    contract = farm.state()["contracts"][0]
    game.locator(".contract").first.get_by_role("button", name="Livrer").click()
    settle(game)

    state = farm.state()
    assert state["cash"] == 2000 + contract["reward"]["cash"]
    assert state["contracts"][0]["id"] != contract["id"], "slot refilled"
    assert farm.log() == ["fulfill_contract"]


def test_missing_products_disable_delivery(game):
    open_tab(game, "market")
    assert game.locator(".contract .chip.missing").count() > 0
    assert game.locator(".contract .btn-primary:not([disabled])").count() == 0
    assert game.locator("#tabs [data-tab=market] .tab-badge").count() == 0


def test_discard_then_wait(game, farm):
    farm.patch({"settings.speed": 100})
    reload(game)
    open_tab(game, "market")

    game.locator(".contract").nth(1).get_by_role("button", name="Jeter").click()
    waiting = game.locator(".contract.waiting")
    waiting.wait_for()
    assert "Nouveau contrat dans" in waiting.inner_text()

    # 600 s / 100 = 6 s, then the card turns into a normal contract.
    waiting.wait_for(state="detached", timeout=10_000)
    assert game.locator(".contract").count() == 6
    assert "2 000" in cash_text(game)
