# Backlog

Ad-hoc ideas, bugs, and improvements noticed outside a story's scope — jot them here as
you think of them so they aren't lost. Not a replacement for `deferred-work.md` (which
holds items deferred from a specific story's review); this is for anything that comes up
independent of a review pass.

## 2026-09-14

- **Admin interface / multi-user login.** Stefan (admin) can add and edit users; each
  user logs in with their own username/password and has their own favorites (today's
  `watchlist` is single-user, tied to no account). Conflicts with NFR5 ("en användare,
  en installation") as currently written — would need that NFR revisited, not just a
  feature added.
- **Bring in stock-analysis expertise.** Stefan wants a discussion partner who knows
  stocks/investing to help interpret the collected figures (owner-count trends,
  streak/spike/sma) before deciding what to build next — not a specific feature yet,
  more a "how should I think about these numbers" conversation.
- **Mobile layout bug on Topplista's "Alla" source mode.** On a phone screen the
  accumulated owner-count number (e.g. "Avanza 534 01…") is clipped by the right edge
  of the screen instead of wrapping/shrinking — reported with a screenshot showing
  Investor B / Volvo B / SAAB B rows all cut off mid-number. "Alla" mode is Story 5.4;
  the mobile row-layout fix was Story 5.1, but that predates "Alla" and evidently
  doesn't cover this wider combined-source number.
