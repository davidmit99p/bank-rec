# Shelf notes

Things worth doing, not doing now. Nothing here is blocking anything.

**This file is only the starting point.** The live version is on the Shelf page
of the site, where it can be edited directly - the first time that page was
opened it took a copy of this file, and everything since has been saved in the
database. Editing this file will not change what the site shows.

---

## Waiting on someone else

### Three things to ask the dev ops guy for

None urgent, but they all remove a workaround.

1. **More domains.** Both subscriptions are at 1 of 1, which is why this app
   lives in a folder of `davidmitchell.me.uk` rather than on its own subdomain.
2. **More databases.** `davidmitchell.me.uk` was full at 2 of 2, which is why
   `entigy_recon` sits on the daveslist subscription instead.
3. **The `zip` PHP extension.** Without it the tool cannot read `.xlsx` files
   and every spreadsheet has to be saved as CSV first. One line of server
   configuration, no code change.

The first two will come up again with Process Manager, so they are worth
asking for regardless. `DEPLOY.md` has the steps to tidy this deployment up
once they are done.

---

## Ready to build, just not asked for yet

### Read .xlsx without the `zip` extension

About half an hour. An `.xlsx` is only a zip file, and one can be unpacked in
plain PHP using `gzinflate`, which needs zlib rather than the `zip` extension.
zlib is far more commonly present. Would mean never having to convert a
spreadsheet to CSV again, whether or not the host ever enables `zip`.

Raised 2026-08-29; David said leave it for now.

### Exporting a reconciliation

Never discussed, but it will be wanted eventually - a file to keep as a working
paper, or to hand to an auditor. Probably a spreadsheet of the matched items
with their rule and run, plus whatever is still open on each side.

### A plain-English summary on the rule form

A sentence at the top of the rule form saying what the rule being built
actually does - "Match any ledger line with any bank line for the same amount,
dated the same day" - updating as the boxes change. The two side forms are
filters rather than match criteria, and that is not obvious from the screen.

Offered 2026-08-29; David said not for now.

---

## Bigger direction

### Named users, then a database per client

Decided 30 August 2026, after weighing both ways: **one installation, one
codebase, a database per client, chosen at login.** Not a shared database with
client tags.

Mainly for David's own clients, and if sold it will be to dozens rather than
hundreds - which is the scale where a database each is comfortable. He owns the
hosting company, so there is no limit on how many databases he can have.

**Why not the shared, tagged approach.** With separate databases one client's
data cannot appear on another's screen, because it is not reachable - no query
could cross, right or wrong. With one tagged database, isolation is a promise
made by every WHERE clause in the application, and holds only while all of them
are correct. A miss shows as one client seeing another's figures, with no error
and no crash. Those are different kinds of safe, and for other people's bank
records the structural one is worth the extra plumbing.

Tagging only wins at hundreds of clients, where a database each stops being
manageable. That is not what this is.

**What it involves:**

1. **Users and login first.** There is no notion of who is using the tool - one
   shared password covers the whole site. Nothing else can start until there is.
2. **A small central database** holding users, clients, and which database each
   client uses.
3. **Connection chosen after sign-in.** The app connects to that client's
   database and nothing else. `includes/db.php` already takes the database name
   from config; this makes it a decision rather than a constant.
4. **A migration runner.** Schema changes have to reach every client database.
   With the numbered migration files already in `sql/` this is a script, but it
   is a step that cannot be skipped, and it should record what has run where.

The existing database becomes the first client.

### The original note on this, for reference

David's own sequencing: reconciliations first (done), then named users, then
several companies.

Today the whole site sits behind one shared HTTP Basic password, which is
enough while it is just him. An in-app login earns its keep when either more
than one person uses it, or a reconciliation needs signing off by a named
person - at the moment every match records the rule and the run, but not who
did it.

Multi-tenant would follow the same shape as reconciliations did: a company id
on the tables, scoped queries, a picker. Additive rather than a rewrite.

---

## Known limits, deliberately left

- **Speed.** No longer a worry for the matching itself. The engine indexes one
  side by amount and then by date rather than comparing everything to
  everything, so 12,000 open items a side takes about 0.15 seconds for one rule
  instead of the 11 seconds it used to. That holds even when thousands of
  transactions share the same amount, which is the hard case. What has not been
  addressed is the transactions SCREEN, which still draws every row - twelve
  thousand rows in one page will be slow in the browser however quick the
  database is.
- **Contra rules pair only.** "Equal and opposite" means two entries. Hunting
  for larger sets that happen to reach zero is how you end up matching things
  that have nothing to do with each other - which it did, before that was
  fixed.
- **Imports before migration 002** have no import record, so they cannot be
  removed as a batch.
