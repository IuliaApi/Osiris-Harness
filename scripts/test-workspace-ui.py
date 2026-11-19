#!/usr/bin/env python3
"""Live Chromium workflow against an isolated, provisioned Osiris database."""
import os
from urllib.parse import urlsplit
from uuid import uuid4

from playwright.sync_api import expect, sync_playwright


base = os.environ.get("OSIRIS_TEST_BASE_URL", "http://127.0.0.1:" + os.getenv("OSIRIS_PORT", "8080")).rstrip("/")
url = urlsplit(base)
if url.scheme != "http" or url.hostname not in {"127.0.0.1", "localhost", "::1", "api"} or url.username or url.password or url.path or url.query or url.fragment:
    raise ValueError("Set OSIRIS_TEST_BASE_URL to the isolated local test service")
owner = os.environ["OSIRIS_TEST_USERNAME"]
password = os.environ["OSIRIS_TEST_PASSWORD"]


def login(page, username, secret):
    page.goto(base + "/dashboard")
    expect(page.get_by_label("Username", exact=True)).to_be_visible()
    page.get_by_label("Username", exact=True).fill(username)
    page.get_by_label("Password", exact=True).fill(secret)
    page.get_by_role("button", name="Sign in", exact=True).click()
    expect(page.locator("#greeting")).to_have_text("Welcome back, " + username)
    expect(page.locator("#api-state")).to_have_text("API connected")


def mutation(page, method, action, expected):
    with page.expect_response(lambda r: r.url.startswith(base + "/") and r.request.method == method) as response:
        action()
    r = response.value
    assert r.status == expected, f"{method} {urlsplit(r.url).path} returned {r.status}, request {r.headers.get('x-request-id')}"
    return r.json() if expected != 204 else None


def open_form(scope, name):
    details = scope.locator("details").filter(has=scope.page.locator("summary", has_text=name))
    details.locator("summary").click()
    return details.locator("form")


def card(scope, title):
    return scope.locator("section.resource-card").filter(has=scope.page.get_by_role("heading", name=title, exact=True))


def cleanup(context, resources):
    """Delete only records captured from this run's successful create responses."""
    if not resources:
        return
    me = context.request.get(base + "/auth/me").json()
    for obj in reversed(resources):
        link = obj["_links"]["delete"]
        assert link["method"] == "DELETE" and link["href"].startswith("/") and not link["href"].startswith("//")
        r = context.request.delete(base + link["href"], headers={"X-CSRF-Token": me["csrfToken"]})
        assert r.status in (204, 404), f"Fixture cleanup failed (HTTP {r.status})"


def exercise(browser):
    context = browser.new_context()
    context.route("**/*", lambda route: route.continue_() if route.request.url.startswith(base + "/") else route.abort())
    page = context.new_page()
    page.on("dialog", lambda dialog: dialog.accept())
    resources = []
    suffix = uuid4().hex[:12]
    project, session, task = "UI project " + suffix, "UI session " + suffix, "UI task " + suffix
    try:
        assert context.request.get(base + "/sessions").status == 401
        login(page, owner, password)
        page.reload()
        expect(page.locator("#greeting")).to_have_text("Welcome back, " + owner)
        page.locator("#dashboard-view").get_by_role("button", name="New project").click()
        page.get_by_label("Project name", exact=True).fill(project)
        resources.append(mutation(page, "POST", lambda: page.get_by_role("button", name="Create project", exact=True).click(), 201))
        page.locator("#dashboard-view").get_by_role("button", name="New session").click()
        page.get_by_label("Session title", exact=True).fill(session)
        page.locator("#session-project").select_option(label=project)
        resources.append(mutation(page, "POST", lambda: page.get_by_role("button", name="Create & open").click(), 201))
        expect(page.locator("#chat-title")).to_have_text(session)
        expect(page.get_by_role("button", name="Send message")).to_be_visible()

        page.get_by_role("button", name="Manage", exact=True).click()
        page.locator("#manage-nav").get_by_role("button", name="Sessions", exact=True).click()
        body = page.locator("#manage-body")
        scope = card(body, session)
        # The isolated fixture can contain multiple pages; follow its visible pagination.
        while True:
            expect(body.locator("h2")).to_have_text("Sessions")
            if scope.count():
                break
            pager = body.get_by_role("button", name="Next page", exact=True)
            if not pager.count():
                raise AssertionError("Created session missing from paginated management list")
            with page.expect_response(lambda r: r.request.method == "GET" and "/sessions?" in r.url):
                pager.click()
        form = open_form(scope, "Append message")
        form.get_by_label("Message", exact=True).fill("Browser persisted this turn.")
        mutation(page, "POST", lambda: form.get_by_role("button", name="Append message").click(), 201)
        scope.get_by_role("button", name="Messages", exact=True).click()
        expect(body.get_by_text("content: Browser persisted this turn.", exact=False)).to_be_visible()
        body.get_by_role("button", name="Back", exact=True).click()
        form = open_form(scope, "Create task")
        form.get_by_label("Title", exact=True).fill(task)
        mutation(page, "POST", lambda: form.get_by_role("button", name="Create task").click(), 201)
        scope.get_by_role("button", name="Tasks", exact=True).click()
        task_scope = card(body, task)
        form = open_form(task_scope, "Transition task")
        form.get_by_label("Next state").select_option("running")
        updated = mutation(page, "PATCH", lambda: form.get_by_role("button", name="Transition task").click(), 200)
        assert updated["status"] == "running"
        body.get_by_role("button", name="Back", exact=True).click()
        scope.get_by_role("button", name="Tool requests", exact=True).click()
        form = open_form(body, "Request file write")
        form.get_by_label("Workspace filename").fill("smoke.txt")
        form.get_by_label("Contents").fill("Reviewed browser write.")
        mutation(page, "POST", lambda: form.get_by_role("button", name="Request file write").click(), 201)
        run = card(body, "smoke.txt")
        expect(run.get_by_role("button", name="Execute approved request")).to_have_count(0)
        mutation(page, "POST", lambda: run.get_by_role("button", name="Approve request").click(), 200)
        executed = mutation(page, "POST", lambda: run.get_by_role("button", name="Execute approved request").click(), 200)
        assert executed["status"] == "completed"
        page.get_by_role("button", name="Model settings & credentials").click()
        model = open_form(body, "Save model")
        model.get_by_label("Model", exact=True).fill("ui-smoke-model")
        model.get_by_role("button", name="Save model").click()
        expect(body.get_by_text("ui-smoke-model", exact=False)).to_be_visible()
        page.get_by_role("button", name="API Checks", exact=True).click()
        expect(page).to_have_url(base + "/api-checks")
        page.reload()
        expect(page.get_by_role("heading", name="API Checks", exact=True)).to_be_visible()

        def run_check(panel_id, button, status="Passed"):
            panel = page.locator("#" + panel_id)
            panel.get_by_role("button", name=button, exact=True).click()
            expect(panel.get_by_role("status")).to_have_text(status)
            return panel.locator("pre")

        expect(run_check("session-reads", "Read sessions")).to_contain_text("HTTP 404")
        page.get_by_label("Writable project").select_option(label=project)
        title = "API check " + suffix
        page.get_by_label("New session title").fill("")
        expect(run_check("session-persistence", "Create, rename and read", "Incomplete")).to_contain_text("Enter a session title")
        page.get_by_label("New session title").fill(title)
        created = mutation(page, "POST", lambda: page.get_by_role("button", name="Create, rename and read").click(), 201)
        resources.append(created)
        expect(page.locator("#session-persistence").get_by_role("status")).to_have_text("Passed")
        expect(page.locator("#session-persistence pre")).to_contain_text(title + " (updated)")

        query = page.locator("#session-query")
        query.get_by_label("Status").select_option("active")
        expect(run_check("session-query", "Query sessions")).to_contain_text("status=active")
        expect(run_check("session-query", "Next page")).to_contain_text("offset=1")
        query.get_by_label("Page size").select_option("5")
        expect(query.get_by_role("button", name="Next page")).to_be_disabled()

        expect(run_check("response-formats", "Check response formats")).to_contain_text("HTTP 406")
        expect(run_check("http-statuses", "Run HTTP checks")).to_contain_text("HTTP 400")
        page.get_by_label("Context session").select_option(label=session)
        expect(run_check("project-context", "Read project context")).to_contain_text("provenance:")
        # Delete this run's second session after selection so the next GET genuinely fails.
        page.get_by_label("Context session").select_option(value=created["id"])
        cleanup(context, [created])
        resources.pop()
        expect(run_check("project-context", "Read project context", "Failed")).to_contain_text("HTTP 404")
    finally:
        cleanup(context, resources)
    page.get_by_role("button", name="Log out", exact=True).click()
    expect(page.get_by_role("button", name="Sign in", exact=True)).to_be_visible()
    assert context.request.get(base + "/auth/me").status == 401
    result = page.goto(base + "/missing-ui-page")
    assert result.status == 404
    expect(page.get_by_role("heading", name="Page not found")).to_be_visible()
    context.close()


with sync_playwright() as pw:
    browser = pw.chromium.launch()
    try:
        exercise(browser)
        viewer = os.getenv("OSIRIS_VIEWER_USERNAME")
        if viewer:
            context = browser.new_context()
            page = context.new_page()
            login(page, viewer, os.environ["OSIRIS_VIEWER_PASSWORD"])
            expect(page.get_by_role("button", name="New project")).to_have_count(0)
            expect(page.get_by_role("button", name="New session")).to_have_count(0)
            page.get_by_role("button", name="API Checks", exact=True).click()
            expect(page.get_by_role("button", name="Read sessions")).to_be_enabled()
            expect(page.get_by_role("button", name="Create, rename and read")).to_have_count(0)
            context.close()
    finally:
        browser.close()
print("PASS: Chromium workspace login, persisted records, tool execution, model choice, API checks, logout and 404")
