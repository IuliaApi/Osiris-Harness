# API Checks

Sign in and open **API Checks** at `/api-checks`. These buttons send requests to the running API with your account's permissions. Each result shows the URL, HTTP status, response type, request ID and body. Use synthetic data: **Create, rename and read** leaves a session in the selected project.

| Action | What it checks |
| --- | --- |
| Read sessions | Reads the session list, follows an item if one is visible and checks that a missing item returns `404`. |
| Query sessions | Choose a status, sort order field and page size. **Next page** follows the link returned by the API when more items are available. |
| Create, rename and read | Choose a writable project and enter a title. The page creates a session, follows its update link, then reads it back to check the persisted title. |
| Check response formats | Reads a visible session as JSON, XML and YAML. An invalid sort field should return `400`; an unsupported response type should return `406`. |
| Read project context | Select a visible session and inspect its local data and external GitHub status. A project without a repository URL or an unavailable upstream can still return `200`; check provenance before treating it as a live GitHub result. |
| Run HTTP checks | Exercises session collection reads, invalid paging and sorting, and the runtime endpoint. |

Use **Refresh data** after changing projects or sessions in another view. For database rollback, cross-account access and browser automation, run the [workspace verification suite](workspace-verification.md).
