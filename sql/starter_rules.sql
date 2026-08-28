-- ---------------------------------------------------------------------------
-- A starter set of rules. Optional - run it after schema.sql if you want
-- something to work with straight away. These were tested against the 2019
-- Entigy NatWest / Sun files and matched about 80% of the ledger.
--
-- Rules are tried in sort_order, so the tightest ones come first and the
-- loosest last. Add your own rules above or below these as you go.
-- ---------------------------------------------------------------------------

INSERT INTO rec_rules
  (name, active, sort_order, date_tol, sign_mode, grouping, max_group, link_desc, notes)
VALUES
  ('Same day, same amount, wording agrees',
   1, 10, 0, 'same', 'one', 4, 1,
   'The safest rule. Both sides on the same date for the same amount, and the descriptions share a word - so ledger "VISPA LTD" matches bank "VISPA LTD QUIK INTERNET VIA MOBILE".'),

  ('Within 3 days, same amount, wording agrees',
   1, 20, 3, 'same', 'one', 4, 1,
   'Picks up the timing difference between when you post something and when it clears the bank.'),

  ('Within 7 days, same amount, wording agrees',
   1, 30, 7, 'same', 'one', 4, 1,
   'For cheques and anything slow to clear.'),

  ('Within 3 days, same amount, wording ignored',
   1, 40, 3, 'same', 'one', 4, 0,
   'A catch-all for items where the bank narrative bears no resemblance to the ledger. Looser, so review these carefully.'),

  ('Several ledger lines add up to one bank line',
   1, 50, 5, 'same', 'many_left', 4, 0,
   'For one cheque or one BACS run covering several invoices. Groups that only balance because part of them cancels itself out are rejected.'),

  ('One ledger line splits into several bank lines',
   0, 60, 5, 'same', 'many_right', 4, 0,
   'The mirror image - off by default, turn it on if you need it.');
