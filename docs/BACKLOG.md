# Backlog

Ideas that are deliberately **not** in the MVP. Do not implement without the student's approval and
a matching update to BAB I scope.

## Blocked on the student / lecturer

- Written approval for decision D1: Midtrans sandbox replaces the mock gateway in BAB I.
- The real list of 13 GMFS tenants, their products and prices (the seeder uses placeholders).
- Midtrans sandbox server/client keys and confirmation that QRIS is enabled for the merchant.
- A public HTTPS tunnel for live notifications.
- UAT schedule, participants and consent.
- Faculty outline for BAB II–VI and the university AI-use policy.

## Possible technical follow-ups

- A per-tenant settlement view (currently settlement is operator-only by decision D4).
- Add `preparing → ready → collected` if the kitchen workflow is ever in scope (decision D3).
- Refund/late-payment workflow instead of manual handling in the Midtrans dashboard.
- PostgreSQL row-level security as a second isolation layer, with a non-owner app role.
- Queue worker for reconciliation if attempt volume grows beyond a scheduled command.
- Password login and token rotation to replace the deterministic seeded bearer tokens.
- Rate limiting on `POST /checkouts` and the notification endpoint.
- Structured logging/metrics for notification outcomes per day.
- Multi-outlet support in the UI (the schema already allows several outlets per tenant).
