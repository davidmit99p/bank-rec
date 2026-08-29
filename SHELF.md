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

### Named users and multi-tenant

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

- **Speed.** Matching is roughly quadratic. Measured on this PHP version: one
  rule over 1,000 open items a side takes 0.07 seconds, 5,000 takes 1.8, and
  10,000 takes 11. So the ceiling is around 10,000 open items a side. Matched
  items drop off the list, so importing a period at a time keeps it far away.
- **Contra rules pair only.** "Equal and opposite" means two entries. Hunting
  for larger sets that happen to reach zero is how you end up matching things
  that have nothing to do with each other - which it did, before that was
  fixed.
- **Imports before migration 002** have no import record, so they cannot be
  removed as a batch.
