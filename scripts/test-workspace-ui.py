#!/usr/bin/env python3
"""Live Chromium workflow against an isolated, provisioned Osiris database."""
import os
import runpy
from http.server import ThreadingHTTPServer
from pathlib import Path
from threading import Event, Thread
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


def command_button(page, name):
    page.keyboard.press("Control+k")
    dialog = page.get_by_role("dialog", name="Commands")
    search = dialog.get_by_role("textbox", name="Search commands")
    expect(search).to_be_focused()
    search.fill(name)
    return dialog.get_by_role("button", name=name, exact=True)


def exercise_code_display(page, session):
    # Seed a real message, not a provider response or fabricated browser state.
    api = page.context.request
    csrf = api.get(base + "/auth/me").json()["csrfToken"]
    code = '<strong data-review-marker="literal">café & tea</strong>\n'
    append = session["_links"]["createMessage"]
    assert append["method"] == "POST"
    response = api.post(base + append["href"], headers={"X-CSRF-Token": csrf},
                        data={"role": "assistant", "content": "Review this markup:\n```html\n" + code + "```"})
    assert response.status == 201
    page.reload()
    expect(page).to_have_url(base + "/chat?thread=" + session["id"])
    block = page.locator("#chat-history .thread-code")
    rendered = block.locator("pre code")
    expect(rendered).to_be_visible()
    assert rendered.text_content() == code
    expect(block.locator("[data-review-marker]")).to_have_count(0)


def exercise_inline_consent(page, run):
    # Only the isolated runner serves this upstream. Never send test prompts to a real provider.
    assert base == "http://api:8095"
    handler = runpy.run_path(str(Path(__file__).with_name("test-ollama-transport.py")))["OllamaFixture"]
    release = Event()
    release.set()

    class GatedProvider(handler):
        def do_POST(self):
            if not release.wait(10):
                self.send_error(504)
                return
            super().do_POST()

    with ThreadingHTTPServer(("0.0.0.0", 11435), GatedProvider) as fixture:
        thread = Thread(target=fixture.serve_forever, daemon=True)
        thread.start()
        try:
            run("/model")
            picker = page.get_by_role("dialog", name="Choose model")
            model = picker.locator('[data-provider="ollama"][data-model="qa:agent"]')
            expect(model).to_be_visible()
            picker.locator("#picker-search").fill("qa:agent")
            expect(picker.locator('[data-model="qa:model"]')).to_have_count(0)
            model.click()
            expect(picker).to_be_hidden()
            run("/tools on")
            run("Write a marker file")
            pending = page.locator("#agent-output [data-run-id]").filter(has=page.get_by_role("button", name="Allow once"))
            expect(pending.locator("pre[data-preview]")).to_have_text("canyon")
            page.reload()
            expect(pending.get_by_role("button", name="Allow once")).to_be_enabled()
            pending.get_by_role("button", name="Deny", exact=True).click()
            expect(page.locator("#agent-state")).to_contain_text("Cancelled")
            run("Write a marker file, then read it back")
            pending.get_by_role("button", name="Allow once").click()
            expect(page.locator("#chat-history .assistant").last).to_contain_text("The stored marker is canyon.")
            run("/model ollama/qa:model")
            run("/tools off")
            release.clear()
            run("/goal success")
            expect(page.locator("#agent-state")).to_contain_text("Thinking")
            expect(page.locator("#agent-clock")).to_be_visible()
            release.set()
            expect(page.locator("#chat-history .assistant").last).to_contain_text("fixture result")
            run("/goal pause")
            expect(page.locator("#toast")).to_have_text("Goal paused.")
            page.reload()
            run("/goal")
            expect(page.locator("#picker-status")).to_have_text("paused: success")
            page.locator("#picker-close").click()
            transcript = page.locator("#chat-history").inner_text()
            run("/btw success")
            expect(page.locator("#side-answer")).to_contain_text("fixture result")
            page.locator("#side-close").click()
            run("/btw")
            expect(page.locator("#side-answer")).to_contain_text("fixture result")
            page.locator("#side-close").click()
            assert page.locator("#chat-history").inner_text() == transcript
        finally:
            release.set()
            fixture.shutdown()
            thread.join()


def exercise_terminal(page, original, resources):
    prompt = page.get_by_label("Message", exact=True)
    feedback = page.locator("#toast")

    def run(text):
        prompt.fill(text)
        prompt.press("Enter")

    def post(request):
        if request.method == "POST":
            posts.append(request.url)

    posts = []
    page.on("request", post)
    prompt.fill("/")
    options = page.locator("#slash-suggestions [role=option]")
    offered = set(options.evaluate_all("(rows) => rows.map(row => row.dataset.command)"))
    assert {"/help", "/model", "/permissions", "/new", "/resume", "/copy", "/goal", "/btw", "/desktop"} <= offered
    expect(page.locator("#chat-form").get_by_label("Provider", exact=True)).to_have_count(0)
    expect(page.locator("#chat-form").get_by_label("Model", exact=True)).to_have_count(0)
    prompt.fill("/per")
    expect(options).to_have_count(1)
    expect(options).to_have_attribute("data-command", "/permissions")
    prompt.press("Escape")
    expect(page.locator("#slash-suggestions")).to_be_hidden()
    prompt.fill("/he")
    prompt.press("Tab")
    expect(prompt).to_have_value("/help ")
    prompt.press("Enter")
    expect(feedback).to_contain_text("/resume [session-id]")
    run("/not-an-osiris-command echo <b>literal</b>")
    expect(feedback).to_contain_text("Unknown command: /not-an-osiris-command")
    expect(page.locator("#chat-history")).not_to_contain_text("/not-an-osiris-command")
    prompt.fill("Keep this unfinished draft")
    prompt.press("ArrowUp")
    expect(prompt).to_have_value("/not-an-osiris-command echo <b>literal</b>")
    prompt.press("ArrowUp")
    expect(prompt).to_have_value("/help")
    prompt.press("ArrowDown")
    prompt.press("ArrowDown")
    expect(prompt).to_have_value("Keep this unfinished draft")
    run("/session")
    expect(feedback).to_contain_text("ID:")
    run("/resume")
    expect(page.locator("#thread-search")).to_be_focused()
    assert posts == [], "Local terminal commands must not start a model run or send chat"
    page.remove_listener("request", post)

    created = mutation(page, "POST", lambda: run("/new"), 201)
    resources.append(created)
    assert created["projectId"] is None
    expect(page).to_have_url(base + "/chat?thread=" + created["id"])
    expect(page.locator("#thread-permissions")).to_contain_text("Ask")
    expect(page.locator("#tools-indicator")).to_have_text("tools on")
    renamed = mutation(page, "PATCH", lambda: run("/name Terminal review"), 200)
    assert renamed["title"] == "Terminal review" and renamed["id"] == created["id"]
    expect(page.locator("#chat-title")).to_have_text("Terminal review")
    run("/model ollama/qa-terminal")
    expect(feedback).to_contain_text("Model: ollama/qa-terminal")
    expect(page.locator("#chat-history")).not_to_contain_text("qa-terminal")
    run("/tools off")
    expect(page.locator("#tools-indicator")).to_have_text("tools off")

    # Save an actual run through the composer. Drop its next browser request before
    # it reaches the API: this exercises ambiguous network failure without a billed
    # model call or a fabricated provider reply. The DB still owns the ready run.
    start = created["_links"]["startAgent"]["href"]
    blocked = []

    def disconnect_step(route):
        if route.request.method == "POST" and route.request.url != base + start:
            blocked.append(route.request.url)
            route.abort("connectionfailed")
        else:
            route.continue_()

    page.route("**/*", disconnect_step)
    try:
        with page.expect_response(lambda r: r.request.method == "POST" and r.url == base + start) as sent:
            run("A plain answer please")
        response = sent.value
        assert response.status == 201
        assert response.request.post_data_json == {"message": "A plain answer please", "provider": "ollama", "model": "qa-terminal", "tools": False}
        saved = response.json()
        expect(page).to_have_url(base + "/errors/503/connection_interrupted")
        assert blocked == [base + saved["_links"]["step"]["href"]]
        page.get_by_role("link", name="Back to chat", exact=True).click()
        expect(page.locator("#agent-state")).to_contain_text("Ready for the next model step")
        expect(page.locator("#agent-output")).to_contain_text("A plain answer please")
        expect(page.get_by_role("button", name="Continue", exact=True)).to_be_enabled()
        expect(page.locator("#tools-indicator")).to_have_text("tools off")
        assert len(blocked) == 1, "Reopening a thread must not resubmit a saved step"
    finally:
        page.unroute("**/*", disconnect_step)
    expect(page.get_by_role("button", name="Cancel run")).to_be_enabled()
    prompt.focus()
    page.keyboard.press("Control+k")
    expect(page.get_by_role("dialog", name="Commands")).to_be_visible()
    page.keyboard.press("Escape")
    expect(prompt).to_be_focused()
    expect(page.get_by_role("button", name="Continue", exact=True)).to_be_enabled()
    cancelled = mutation(page, "POST", lambda: prompt.press("Escape"), 200)
    assert cancelled["id"] == saved["id"] and cancelled["status"] == "cancelled"
    expect(page.locator("#agent-state")).to_contain_text("Cancelled")
    expect(page.get_by_role("button", name="Continue", exact=True)).to_have_count(0)
    if base == "http://api:8095":
        exercise_inline_consent(page, run)
    run("/resume " + original["id"])
    expect(page).to_have_url(base + "/chat?thread=" + original["id"])
    expect(page.locator("#chat-history .thread-code")).to_be_visible()
    if page.evaluate("window.isSecureContext"):
        page.context.grant_permissions(["clipboard-read", "clipboard-write"], origin=base)
    run("/copy")
    expect(feedback).to_contain_text("Model reply copied.")
    prompt.fill("")
    prompt.press("Control+v")
    expect(prompt).to_have_value('Review this markup:\n```html\n<strong data-review-marker="literal">café & tea</strong>\n```')


def exercise_thread_workbench(page, session, resources):
    first_url = base + "/chat?thread=" + session["id"]
    title = "Markup review"
    message = page.get_by_label("Message", exact=True)

    def rename(dialog):
        assert dialog.type == "prompt"
        dialog.accept(title)

    page.once("dialog", rename)
    renamed = mutation(page, "PATCH", lambda: page.get_by_role("button", name="Rename thread", exact=True).click(), 200)
    assert renamed["id"] == session["id"] and renamed["title"] == title
    expect(page.locator("#chat-title")).to_have_text(title)
    command_button(page, "Focus message").press("Enter")
    expect(message).to_be_focused()
    message.fill("/permissions")
    message.press("Enter")
    first_mode = mutation(page, "PUT", page.locator('#picker-list [data-mode="allow"]').click, 200)
    assert first_mode == {"mode": "allow"}
    message.fill("First thread's unfinished review")

    second = mutation(page, "POST", command_button(page, "New chat").click, 201)
    resources.append(second)
    assert second["id"] != session["id"] and second["projectId"] is None
    second_url = base + "/chat?thread=" + second["id"]
    expect(page).to_have_url(second_url)
    expect(page.locator("#chat-title")).to_have_text("New chat")
    expect(message).to_be_editable()
    expect(message).to_have_value("")
    expect(page.locator("#thread-permissions")).to_contain_text("Ask")
    page.locator("#thread-permissions").click()
    second_mode = mutation(page, "PUT", page.locator('#picker-list [data-mode="yolo"]').click, 200)
    assert second_mode == {"mode": "yolo"}
    message.fill("Second thread's separate draft")

    rail = page.locator("#chat-session-list")
    rail.get_by_role("button", name=title, exact=False).click()
    expect(page).to_have_url(first_url)
    expect(message).to_have_value("First thread's unfinished review")
    expect(page.locator("#thread-permissions")).to_contain_text("Allow")
    exercise_code_display(page, session)
    expect(page.locator("#chat-title")).to_have_text(title)
    expect(message).to_have_value("First thread's unfinished review")
    rail.get_by_role("button", name="New chat", exact=False).click()
    expect(page).to_have_url(second_url)
    expect(message).to_have_value("Second thread's separate draft")
    expect(page.locator("#thread-permissions")).to_contain_text("YOLO")
    exercise_terminal(page, session, resources)


def exercise_private_chat(page):
    expect(page.locator("#count-projects")).to_have_text("0")
    expect(page.locator("#count-sessions")).to_have_text("0")
    with page.expect_response(lambda r: r.request.method == "POST" and r.url == base + "/sessions") as pending:
        page.get_by_role("button", name="New chat", exact=True).click()
    response = pending.value
    assert response.status == 201
    session = response.json()
    resources = [session]
    try:
        assert response.request.post_data_json == {"title": "New chat"}
        assert session["projectId"] is None and "project" not in session["_links"]
        expect(page).to_have_url(base + "/chat?thread=" + session["id"])
        expect(page.locator("#chat-title")).to_have_text("New chat")
        expect(page.locator("#chat-project")).to_have_text("Private chat")
        expect(page.get_by_label("Message", exact=True)).to_be_editable()
        expect(page.get_by_role("button", name="Send message", exact=True)).to_be_disabled()
        expect(page.get_by_role("button", name="New chat", exact=True)).to_be_enabled()

        page.get_by_role("button", name="API Checks", exact=True).click()
        panel = page.locator("#project-context")
        panel.get_by_label("Context session").select_option(value=session["id"])
        panel.get_by_role("button", name="Read project context", exact=True).click()
        expect(panel.get_by_role("status")).to_have_text("Passed")
        expect(panel.locator("pre")).to_contain_text('"project": null')

        page.get_by_role("button", name="Dashboard", exact=True).click()
        page.reload()
        expect(page.locator("#count-projects")).to_have_text("0")
        expect(page.locator("#count-sessions")).to_have_text("1")
        recent = page.locator("#recent-sessions").get_by_role("button", name="New chat", exact=False)
        expect(recent).to_contain_text("Private chat")
        with page.expect_response(lambda r: r.request.method == "GET" and urlsplit(r.url).path == session["_links"]["self"]["href"]) as saved:
            recent.click()
        assert saved.value.json()["id"] == session["id"]
        expect(page.locator("#chat-title")).to_have_text("New chat")
        expect(page.get_by_label("Message", exact=True)).to_be_editable()
        exercise_thread_workbench(page, session, resources)
    finally:
        cleanup(page.context, resources)


def exercise_registration(browser, private_project):
    """A fresh browser profile must never inherit the fixture owner's access."""
    context = browser.new_context()
    try:
        page = context.new_page()
        username = "Signup_" + uuid4().hex
        secret = "é-" + uuid4().hex
        page.goto(base + "/login")
        signup = page.get_by_role("link", name="Create account", exact=True)
        signup.press("Enter")
        expect(page).to_have_url(base + "/register")
        page.get_by_role("link", name="Back to sign in").press("Enter")
        expect(page).to_have_url(base + "/login")
        signup.press("Enter")
        page.get_by_label("Username", exact=True).fill(username)
        field = page.get_by_label("Password", exact=True)
        rules = page.get_by_role("list", name="Password requirements")
        minimum = rules.get_by_role("checkbox", name="At least 12 bytes")
        maximum = rules.get_by_role("checkbox", name="At most 72 bytes")
        field.focus()
        expect(rules).to_be_hidden()
        field.fill("short")
        expect(minimum).not_to_be_checked()
        expect(maximum).to_be_checked()
        field.fill("é" * 37)  # 74 UTF-8 bytes but only 37 characters.
        expect(minimum).to_be_checked()
        expect(maximum).not_to_be_checked()
        field.press("Enter")
        expect(page).to_have_url(base + "/register")
        field.fill("")
        expect(rules).to_be_hidden()
        field.fill(secret)
        expect(minimum).to_be_checked()
        expect(maximum).to_be_checked()
        created = mutation(page, "POST", lambda: field.press("Enter"), 201)
        expect(page).to_have_url(base + "/dashboard")
        expect(page.locator("#greeting")).to_have_text("Welcome back, " + username)
        expect(page.locator("#api-state")).to_have_text("API connected")
        assert created["user"]["username"] == username and created["user"]["role"] == "member"
        assert context.request.get(base + "/auth/me").json() == created
        project_url = base + private_project["_links"]["self"]["href"]
        assert context.request.get(project_url).status == 404

        page.reload()
        expect(page.locator("#greeting")).to_have_text("Welcome back, " + username)
        expect(page.locator("#api-state")).to_have_text("API connected")
        page.get_by_role("button", name="Log out", exact=True).click()
        expect(page.get_by_role("button", name="Sign in", exact=True)).to_be_visible()
        assert context.request.get(base + "/auth/me").status == 401
        page.get_by_role("link", name="Create account", exact=True).click()
        page.get_by_label("Username", exact=True).fill(username)
        page.get_by_label("Password", exact=True).fill(secret)
        page.get_by_role("button", name="Create account", exact=True).click()
        expect(page).to_have_url(base + "/errors/409/conflict")
        expect(page.get_by_role("heading", name="Record conflict")).to_be_visible()
        page.get_by_role("link", name="Back to registration", exact=True).click()
        expect(page).to_have_url(base + "/register")
        expect(page.get_by_label("Password", exact=True)).to_have_value("")
        login(page, username, secret)
        assert context.request.get(base + "/auth/me").json()["user"] == created["user"]
        assert context.request.get(project_url).status == 404
        exercise_private_chat(page)
    finally:
        context.close()


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
        exercise_registration(browser, resources[0])
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
        error_link = page.locator('#response-formats a[href="/errors/406/not_acceptable"]')
        with context.expect_page() as opened:
            error_link.click()
        details = opened.value
        expect(details.get_by_role("heading", name="Response format not available")).to_be_visible()
        expect(details).to_have_url(base + "/errors/406/not_acceptable")
        details.close()
        expect(page.locator("#response-formats").get_by_role("status")).to_have_text("Passed")
        expect(run_check("http-statuses", "Run HTTP checks")).to_contain_text("HTTP 400")
        page.get_by_label("Context session").select_option(label=session)
        expect(run_check("project-context", "Read project context")).to_contain_text("provenance:")
        # Delete this run's second session after selection so the next GET genuinely fails.
        page.get_by_label("Context session").select_option(value=created["id"])
        cleanup(context, [created])
        resources.pop()
        expect(run_check("project-context", "Read project context", "Failed")).to_contain_text("HTTP 404")
        expect(page.locator('#project-context a[href="/errors/404/not_found"]')).to_be_visible()
    finally:
        cleanup(context, resources)
    # An expired session leads to its own recovery page instead of an opaque
    # redirect or an automatic re-submission. Sign out in another real tab.
    other = context.new_page()
    other.goto(base + "/dashboard")
    expect(other.locator("#api-state")).to_have_text("API connected")
    with other.expect_response(lambda r: r.url == base + "/auth/logout" and r.request.method == "POST") as logout:
        other.get_by_role("button", name="Log out", exact=True).click()
    assert logout.value.status == 204
    other.close()
    page.get_by_role("button", name="Dashboard", exact=True).click()
    expect(page).to_have_url(base + "/errors/401/unauthorized")
    page.get_by_role("link", name="Sign in", exact=True).click()
    expect(page.get_by_role("button", name="Sign in", exact=True)).to_be_visible()
    assert context.request.get(base + "/auth/me").status == 401
    expect(page).to_have_url(base + "/login")
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
            expect(page.get_by_role("button", name="New chat", exact=True)).to_have_count(0)
            page.get_by_role("button", name="Chat", exact=True).click()
            expect(page.locator("#chat-view")).to_be_visible()
            expect(page.get_by_role("button", name="New chat", exact=True)).to_have_count(0)
            page.get_by_role("button", name="API Checks", exact=True).click()
            expect(page.get_by_role("button", name="Read sessions")).to_be_enabled()
            expect(page.get_by_role("button", name="Create, rename and read")).to_have_count(0)
            context.close()
    finally:
        browser.close()
print("PASS: Chromium registration, isolation, slash commands, model selection, per-thread permissions, inline consent, recovery, persistence and API checks")
