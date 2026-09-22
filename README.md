# Osiris

**A persistent AI coding agent that follows your work wherever you go.**

Osiris is an AI-powered coding CLI inspired by agentic development tools such as Grok Build. It provides a coding agent that works directly with a developer's environment while synchronizing accounts, projects, conversations, and coding sessions through a central REST API.

The main idea behind Osiris is simple: **your coding sessions should not be tied to one computer.**

Log into Osiris from another machine and your existing sessions are available without manually copying session files or conversation history.

```text id="9aavwk"
$ osiris login
$ osiris sessions
$ osiris resume <sessionId>
```

---

# How Osiris Works

Osiris consists of two primary parts:

```text id="wuzf93"
                  OSIRIS API
             ┌─────────────────────┐
             │                     │
             │  Users              │
             │  Projects           │
             │  Sessions           │
             │  Messages           │
             │  Tasks              │
             │                     │
             └──────────┬──────────┘
                        │
                     HTTPS
                        │
            ┌───────────┴───────────┐
            │                       │
       ┌────▼─────┐            ┌────▼─────┐
       │   PC A   │            │   PC B   │
       │          │            │          │
       │  Osiris  │            │  Osiris  │
       │   CLI    │            │   CLI    │
       └──────────┘            └──────────┘
```

The **Osiris CLI** runs locally and interacts with the developer's environment.

The **Osiris API** provides authentication and persistent storage for projects, sessions, messages, and agent tasks.

This allows a session created on one computer to be resumed from another.

For example:

```text id="72fq9s"
PC A

$ osiris login
$ osiris new

Session created: ses_82fa
```

Later, from another machine:

```text id="n3zv29"
PC B

$ osiris login
$ osiris sessions

ID         PROJECT       STATUS
--------------------------------
ses_82fa   Elara         active

$ osiris resume ses_82fa
```

The session is restored through the Osiris API.

Source code itself does not need to be hosted by Osiris. Projects can continue using Git repositories while Osiris synchronizes the **AI session state, conversation history, and task information**.

---

# Core Features

- AI-powered coding CLI
- User accounts
- Secure authentication
- Persistent coding sessions
- Cloud-synchronized conversation history
- Cross-device session restoration
- Project management
- Agent task tracking
- Session searching and filtering
- REST API
- OpenAPI 3.0 specification

---

# Technology Stack

Osiris is designed primarily around **Rust**, using it for both the local coding agent and the REST API backend.

- **Rust** — Primary programming language used for the Osiris CLI, coding agent, and backend services.
- **Tokio** — Asynchronous Rust runtime used for networking, concurrent tasks, and other asynchronous operations.
- **Axum** — Rust web framework used to implement the Osiris REST API and its HTTP endpoints.
- **Serde / Serde JSON** — Serialization and deserialization of API requests, responses, configuration, and session data.
- **Reqwest** — HTTP client used by the Osiris CLI to communicate with the Osiris REST API.
- **Clap** — Command-line argument parser used to implement commands such as `osiris login`, `osiris sessions`, and `osiris resume`.
- **PostgreSQL** — Main database used by the Osiris backend to persist users, projects, sessions, messages, and tasks.
- **SQLx** — Asynchronous Rust database library used to communicate with PostgreSQL.
- **JWT Authentication** — Bearer-token authentication used to authenticate CLI requests to the Osiris API.
- **Argon2** — Password hashing used to securely store account credentials.
- **OpenAPI 3.0** — Defines and documents the contract between the Osiris CLI and REST API. See the [OpenAPI specification](docs/openai.yaml).
- **Swagger Editor / Swagger UI** — Used to validate, inspect, and test the OpenAPI specification.
- **Git** — Version control for Osiris and the external projects that Osiris works with.
- **GitHub** — Repository hosting and collaborative development.
- **Docker** — Provides reproducible development and deployment environments for the API and PostgreSQL database.

---

# Osiris REST API

The Osiris backend exposes a REST API used by the CLI.

## API Information

```yaml id="7a17pn"
openapi: 3.0.3

info:
  title: Osiris API
  version: 1.0.0
  description: >
    REST API for the Osiris AI coding CLI with user accounts,
    projects, and cloud-synchronized coding sessions.
```

---

# Servers

## Development

```text id="ofc04j"
http://localhost:8080
```

The development server can use port forwarding to allow other computers to connect for testing.

## Production

```text id="m54z18"
https://api.osiris.example.com
```

The production domain is currently a placeholder and will be replaced with the real Osiris API domain.

---

# Core Resources

The Osiris API is built around five primary REST resources:

```text id="gsnaxh"
Users
Projects
Sessions
Messages
Tasks
```

These resources represent the main data required to provide persistent coding sessions across devices.

---

# Users

Users represent Osiris accounts.

Accounts allow projects and coding sessions to be associated with a specific developer and retrieved from another computer after authentication.

### Endpoints

```text id="lvyt2p"
POST   /users
GET    /users/me
PATCH  /users/me
DELETE /users/me
```

Authentication endpoints:

```text id="tzut0q"
POST /auth/login
POST /auth/logout
```

---

# Projects

Projects represent codebases that Osiris works with.

A project can contain:

- Project ID
- Name
- Primary programming language
- Repository URL
- Creation date
- Last update date

### Endpoints

```text id="hr0ygp"
POST   /projects
GET    /projects
GET    /projects/{projectId}
PATCH  /projects/{projectId}
DELETE /projects/{projectId}
```

Projects can be searched or filtered using query parameters.

```text id="9jxhzv"
GET /projects?name=elara

GET /projects?language=cpp
```

---

# Sessions

Sessions are persistent interactions between a developer and the Osiris coding agent.

Instead of storing the only copy of the session locally, Osiris associates the session with the authenticated account.

### Endpoints

```text id="2jypbu"
POST   /sessions
GET    /sessions
GET    /sessions/{sessionId}
PATCH  /sessions/{sessionId}
DELETE /sessions/{sessionId}
```

Sessions support filtering and searching.

```text id="nh56cb"
GET /sessions?status=active

GET /sessions?projectId=prj_123

GET /sessions?createdAfter=2026-09-01T00:00:00Z

GET /sessions?search=renderer
```

Possible session states:

```text id="jpwfmp"
active
completed
archived
```

---

# Messages

Messages represent conversation history inside an Osiris session.

A message can originate from:

```text id="3bn7e7"
user
assistant
system
```

### Endpoints

```text id="wm68dm"
GET  /sessions/{sessionId}/messages
POST /sessions/{sessionId}/messages
```

Messages can also be filtered.

```text id="zttelz"
GET /sessions/ses_123/messages?role=user

GET /sessions/ses_123/messages?role=assistant

GET /sessions/ses_123/messages?after=2026-09-20T00:00:00Z
```

---

# Tasks

Tasks represent individual pieces of work being performed by the Osiris agent.

For example:

```text id="5ij86r"
Inspect renderer.cpp and determine the source of the crash.
```

### Endpoints

```text id="hx0jzi"
GET  /sessions/{sessionId}/tasks
POST /sessions/{sessionId}/tasks
```

Tasks can be filtered by status:

```text id="s2p6ks"
GET /sessions/ses_123/tasks?status=running

GET /sessions/ses_123/tasks?status=completed
```

Possible task states:

```text id="9nwszu"
pending
running
completed
failed
cancelled
```

---

# Authentication

Osiris uses bearer-token authentication.

A user first logs in through:

```text id="55l8fp"
POST /auth/login
```

Example request:

```json id="t80htr"
{
  "email": "user@example.com",
  "password": "password123"
}
```

The Osiris API returns an access token:

```json id="z2q4om"
{
  "accessToken": "example-token",
  "tokenType": "Bearer"
}
```

The CLI then includes the token when accessing protected resources.

```text id="5b2bl1"
$ osiris login
       │
       ▼
POST /auth/login
       │
       ▼
  Access Token
       │
       ▼
GET /sessions
Authorization: Bearer <token>
       │
       ▼
Osiris identifies account
       │
       ▼
Synchronized Sessions
```

Because the authenticated account is identified through the token, requests do not need to include a user ID in every URI.

For example:

```text id="njr9fg"
GET /sessions
```

means:

```text id="cwsixw"
Retrieve the sessions belonging to the authenticated user.
```

---

# Data Models

## User

```text id="z2xk7i"
id          string
username    string
email       string
createdAt   date-time
```

## Project

```text id="cmum4h"
id              string
name            string
language        string
repositoryUrl   string
createdAt       date-time
updatedAt       date-time
```

## Session

```text id="un48j6"
id          string
projectId   string
title       string
status      string
createdAt   date-time
updatedAt   date-time
```

## Message

```text id="1vnsze"
id          string
sessionId   string
role        string
content     string
createdAt   date-time
```

## Task

```text id="eqajps"
id            string
sessionId     string
description   string
status        string
createdAt     date-time
completedAt   date-time
```

---

# OpenAPI

The complete Osiris REST API contract is defined in:

```text id="43nyj3"
openapi.yaml
```

The specification uses:

```text id="fbv2qa"
OpenAPI 3.0.3
```

It defines:

- API metadata
- Development and production servers
- REST resources
- HTTP methods
- Path parameters
- Query parameters
- Request bodies
- Response bodies
- HTTP status codes
- Bearer authentication
- Reusable data schemas

The specification can be validated using Swagger Editor.

---

# Example Osiris Workflow

### Login

```text id="7akf46"
$ osiris login

Email: user@example.com
Password: ********

Logged into Osiris.
```

### Start a coding session

```text id="sdgwkp"
$ osiris new

Project: Elara

Session created: ses_82fa
```

### View synchronized sessions

```text id="rs8hy6"
$ osiris sessions

ID         PROJECT       STATUS       UPDATED
-------------------------------------------------
ses_82fa   Elara         active       2 min ago
ses_71ac   Sunder        completed    Yesterday
ses_19bd   Website       archived     Sep 18
```

### Resume from another computer

```text id="qgwty5"
$ osiris login
$ osiris resume ses_82fa

Restoring session...

Session restored.
```

---

# Development

During development, the Osiris API runs at:

```text id="zldwts"
http://localhost:8080
```

The basic development architecture is:

```text id="6l4yrw"
       Osiris CLI
           │
           │ HTTP
           ▼
   localhost:8080
           │
           ▼
      Osiris API
           │
     ┌─────┼───────────────┐
     │     │       │       │
   Users Projects Sessions Messages
                         │
                       Tasks
```

---

# Project Status

Osiris is currently in the **API design and architecture stage**.

The initial OpenAPI specification defines the REST contract that will be used for communication between the Osiris CLI and backend.

Future development will implement the API, persistent storage, authentication, and the Osiris coding agent.