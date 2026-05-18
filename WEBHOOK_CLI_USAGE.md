# Webhook CLI Usage Guide

Command-line tool for managing iThenticate webhook registrations.

```bash
php plugins/generic/plagiarism/tools/webhook.php <COMMAND> [OPTIONS]
```

## Quick Start

```bash
# 1. Register a webhook for your journal
php plugins/generic/plagiarism/tools/webhook.php register --context=yourjournalpath

# 2. Validate it's working
php plugins/generic/plagiarism/tools/webhook.php validate --context=yourjournalpath

# 3. List all configured webhooks
php plugins/generic/plagiarism/tools/webhook.php list
```

---

## Commands

### register

Register a new webhook for a context. Idempotent — checks if a webhook already exists before creating one.

```bash
php plugins/generic/plagiarism/tools/webhook.php register --context=journal-slug
php plugins/generic/plagiarism/tools/webhook.php register --context=1
```

**Behavior:**
- Checks if a webhook already exists (in DB, or in both DB and API with `--include-api`)
- If a valid webhook exists, displays a warning and exits
- If missing, creates a new webhook via `update`

**With `--include-api`:**
```bash
php plugins/generic/plagiarism/tools/webhook.php register --include-api --context=journal-slug
```
- Checks both DB and iThenticate API for an existing webhook
- Reports **orphaned** state if found at API but not in DB
- Reports **mismatch** if DB and API have different webhook IDs for the same URL

**Success output:**
```
Operation completed successfully.
┌──────────────┬─────────────────────────┬─────────────────┬───────────────┐
│ id           │ url                     │ event_types     │ url reachable │
├──────────────┼─────────────────────────┼─────────────────┼───────────────┤
│ webhook-456  │ https://example.com/... │ SUBMISSION_...  │ YES           │
└──────────────┴─────────────────────────┴─────────────────┴───────────────┘
```

---

### update

Delete existing webhook (if any) and register a new one with a fresh signing secret.

```bash
# Normal update
php plugins/generic/plagiarism/tools/webhook.php update --context=journal-slug

# Force update (continue even if API deletion fails)
php plugins/generic/plagiarism/tools/webhook.php update --context=journal-slug --force

# Full orphaned webhook recovery (find by URL at API, delete, re-register)
php plugins/generic/plagiarism/tools/webhook.php update --include-api --context=journal-slug
```

**When to use:**
- After changing API credentials (`api_url` or `api_key`)
- Webhook URL changed (server migration)
- Signing secret needs rotation
- Recovering from orphaned webhook state

---

### delete

Delete a webhook from iThenticate API and clear local database records.

```bash
# Delete using webhook ID from database
php plugins/generic/plagiarism/tools/webhook.php delete --context=journal-slug

# Delete with API-level lookup (finds webhook by URL)
php plugins/generic/plagiarism/tools/webhook.php delete --include-api --context=journal-slug

# Delete a specific webhook by ID (no context needed)
php plugins/generic/plagiarism/tools/webhook.php delete --include-api \
    --api-url=https://app.ithenticate.com/api --api-key=KEY --webhook-id=abc-123

# Force delete (skip API deletion failure)
php plugins/generic/plagiarism/tools/webhook.php delete --context=journal-slug --force
```

**Behavior:**
- Resolves webhook ID using the [priority order](#webhook-id-resolution)
- Deletes from iThenticate API
- Clears DB records only if the deleted webhook ID matches what's stored in the database
- If the webhook is not found at the API (404), warns but proceeds to clean up the DB record
- If `--webhook-id` differs from the DB record, preserves the DB record (useful for cleaning up duplicates)

**Output examples:**
```
# Success
Successfully deleted the iThenticate webhook with id: webhook-uuid-123 for context id: 1

# Nothing to delete (DB only)
No webhook found in the database for this context. Use --include-api to also check the iThenticate API.

# Nothing to delete (with --include-api)
No webhook found at iThenticate API for this context. Nothing to delete.

# Webhook already gone from API
Webhook abc-123 was not found at iThenticate API (may have been deleted already).
Cleaning up local database record.

# DB record preserved (deleted ID differs from DB)
Deleted webhook explicit-id differs from the database record (db-stored-id).
Database record preserved.
```

---

### validate

Verify webhook configuration and test URL reachability.

```bash
# Validate using webhook ID from database
php plugins/generic/plagiarism/tools/webhook.php validate --context=journal-slug

# Validate with API lookup (find webhook by URL)
php plugins/generic/plagiarism/tools/webhook.php validate --include-api --context=journal-slug

# Validate a specific webhook (no context needed)
php plugins/generic/plagiarism/tools/webhook.php validate --include-api \
    --api-url=URL --api-key=KEY --webhook-id=ID
```

**Behavior:**
- Retrieves webhook configuration from iThenticate API
- Tests URL accessibility (HEAD then GET request, 10-second timeout)
- Displays configuration table with reachability status
- With `--include-api`: warns if the validated webhook ID differs from the DB record

**Success output:**
```
┌────────────────────┬──────────────────────────────────────┬─────────────────┬───────────────┐
│ id                 │ url                                  │ event_types     │ url reachable │
├────────────────────┼──────────────────────────────────────┼─────────────────┼───────────────┤
│ abc123-webhook-id  │ https://journal.com/webhooks/handle  │ SUBMISSION_C... │ YES           │
└────────────────────┴──────────────────────────────────────┴─────────────────┴───────────────┘
```

**On failure**, full API response details are displayed for debugging (status code, response body, headers).

---

### list

List webhook configurations for all contexts and optionally from iThenticate API.

```bash
# List all contexts and their DB webhook status
php plugins/generic/plagiarism/tools/webhook.php list

# Include API webhooks with context cross-reference
php plugins/generic/plagiarism/tools/webhook.php list --include-api --context=journal-slug

# API-only listing with explicit credentials
php plugins/generic/plagiarism/tools/webhook.php list --include-api \
    --api-url=https://app.ithenticate.com/api --api-key=YOUR_KEY
```

**DB output (default):**
```
┌────┬─────────────┬──────────────────┬────────────┐
│ ID │ Path        │ Webhook ID       │ Configured │
├────┼─────────────┼──────────────────┼────────────┤
│ 1  │ testjournal │ webhook-uuid-123 │ Yes        │
│ 2  │ journal2    │ Not configured   │ No         │
└────┴─────────────┴──────────────────┴────────────┘
```

**API output (`--include-api`):**

Each API webhook is displayed as a card with wrapped values:
```
API Webhooks (iThenticate)
--------------------------

Webhook 1 of 2
┌─────────────────┬────────────────────────────────────────────────────────────┐
│ Property        │ Value                                                      │
├─────────────────┼────────────────────────────────────────────────────────────┤
│ Webhook ID      │ webhook-uuid-123                                           │
│ URL             │ https://site.com/index.php/journal/$$$call$$$/plugins/gen  │
│                 │ eric/plagiarism/controllers/plagiarism-webhook/handle       │
│ Events          │ SUBMISSION_COMPLETE, SIMILARITY_UPDATED, PDF_STATUS,       │
│                 │ GROUP_ATTACHMENT_COMPLETE, SIMILARITY_COMPLETE              │
│ Created         │ 2025-11-26T10:30:00.000Z                                  │
│ Matches Context │ YES (URL + DB)                                             │
└─────────────────┴────────────────────────────────────────────────────────────┘
```

**"Matches Context" values:**
| Value | Meaning |
|-------|---------|
| `YES (URL + DB)` | Healthy — matches both context URL and DB record |
| `YES (URL match, not in DB)` | Orphaned — URL matches but not tracked in DB |
| `YES (DB match, URL differs)` | Stale — DB has this ID but URL has changed |
| `-` | No match to the current context |

---

### usage

Display help information about available commands.

```bash
php plugins/generic/plagiarism/tools/webhook.php usage
```

---

## Global Options

| Option | Description |
|--------|-------------|
| `--context=<PATH_OR_ID>` | Context path or numeric ID |
| `--include-api` | Include iThenticate API-level operations alongside DB |
| `--api-url=<URL>` | Explicit iThenticate API URL (overrides context credentials) |
| `--api-key=<KEY>` | Explicit iThenticate API key (overrides context credentials) |
| `--webhook-id=<ID>` | Explicit webhook ID (bypasses URL-based lookup) |
| `--force` | Skip failures and force operations |

### When is `--context` required?

| Command | Without `--include-api` | With `--include-api` |
|---------|------------------------|---------------------|
| register | Required | Required |
| update | Required | Required |
| delete | Required | Required, unless `--webhook-id` + `--api-url` + `--api-key` |
| validate | Required | Required, unless `--webhook-id` + `--api-url` + `--api-key` |
| list | Not required | Required, unless `--api-url` + `--api-key` |
| work | Required | N/A (flag ignored) |

### Credential Resolution Order

When API access is needed, credentials are resolved in priority order:

1. **Explicit CLI params** (`--api-key` + `--api-url`) — highest priority
2. **Context forced credentials** (from `[ithenticate]` section in `config.inc.php`)
3. **Context plugin settings** (from database)

---

## Webhook ID Resolution

When a command needs to determine which webhook to operate on, the ID is resolved in this order:

1. **Explicit `--webhook-id`** — always used if provided (highest priority)
2. **API lookup by URL** — if `--include-api` is set and context is available, finds the webhook at iThenticate API that matches the context's URL
3. **DB-stored ID** — falls back to the `ithenticateWebhookId` stored in the database for the context

---

## Common Scenarios

### Initial Setup

```bash
# Register webhook for each journal/press/server
php plugins/generic/plagiarism/tools/webhook.php register --context=journal1
php plugins/generic/plagiarism/tools/webhook.php register --context=journal2

# Verify they're working
php plugins/generic/plagiarism/tools/webhook.php validate --context=journal1
php plugins/generic/plagiarism/tools/webhook.php validate --context=journal2
```

### After Changing API Credentials

```bash
# Update webhooks (deletes old, registers new)
php plugins/generic/plagiarism/tools/webhook.php update --context=journal-slug

# Validate the new webhook
php plugins/generic/plagiarism/tools/webhook.php validate --context=journal-slug
```

### Orphaned Webhook Recovery

A webhook is "orphaned" when it exists at iThenticate API but isn't tracked in your database (e.g., the DB save failed after API registration).

**Symptoms:**
- `list` shows "Not configured" for the context
- `register` fails (webhook URL already exists at API)

**One-command fix:**
```bash
php plugins/generic/plagiarism/tools/webhook.php update --include-api --context=journal-slug
```

**Step-by-step diagnosis:**
```bash
# 1. See what's at the API
php plugins/generic/plagiarism/tools/webhook.php list --include-api --context=journal-slug
# Look for "YES (URL match, not in DB)" in the Matches Context column

# 2. Delete the orphaned webhook
php plugins/generic/plagiarism/tools/webhook.php delete --include-api --context=journal-slug

# 3. Re-register properly
php plugins/generic/plagiarism/tools/webhook.php register --context=journal-slug
```

### Cleaning Up Duplicate Webhooks

If multiple webhooks were accidentally registered for the same URL:

```bash
# 1. List all API webhooks to see duplicates
php plugins/generic/plagiarism/tools/webhook.php list --include-api --context=journal-slug

# 2. Delete specific unwanted webhooks by ID
php plugins/generic/plagiarism/tools/webhook.php delete --include-api \
    --api-url=URL --api-key=KEY --webhook-id=UNWANTED_ID

# 3. Repeat for each duplicate
```

### Server Migration / URL Change

```bash
# Update webhook with new URL (deletes old, registers with new URL)
php plugins/generic/plagiarism/tools/webhook.php update --context=journal-slug

# If old webhook is orphaned at API (URL changed, can't find by new URL)
php plugins/generic/plagiarism/tools/webhook.php update --context=journal-slug --force
```

### API Audit (No Context Needed)

```bash
# List all webhooks registered for your iThenticate account
php plugins/generic/plagiarism/tools/webhook.php list --include-api \
    --api-url=https://app.ithenticate.com/api --api-key=YOUR_KEY
```

## Finding Your Context Path

The `--context` option accepts either a **path** or a **numeric ID**:

- **Path**: Found in `Administration > Hosted Journals > Settings Wizard` (the URL path for your journal)
- **ID**: The numeric context ID from the database

Both are interchangeable:
```bash
php plugins/generic/plagiarism/tools/webhook.php register --context=myjournal
php plugins/generic/plagiarism/tools/webhook.php register --context=1
```
