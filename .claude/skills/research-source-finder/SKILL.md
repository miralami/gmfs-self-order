---
name: research-source-finder
description: Find, retrieve full text, and extract evidence from academic sources for a thesis/report literature review. Use when searching papers, references, jurnal, or building a literature matrix.
---

# Research Source Finder

Goal: real, verifiable sources read in full text where possible, not abstract-only summaries. Never invent a paper, DOI, author, quote, or page number.

## 0. Setup (every session)
- Confirm: topic, research questions, field, year range (default last 5-10 years), language (EN + ID), target count (default 10-20 core papers), citation style (default APA 7).
- Check which tools exist: claude.ai connectors (Consensus, Wiley Scholar Gateway, PubMed), MCP servers in `.mcp.json` (paper-search), WebSearch/WebFetch, Claude in Chrome. Say in one line which ones are used.
- Read the repo's existing reference files first (see CLAUDE.md / AGENTS.md "Research & penulisan" section) to avoid duplicates.

## 1. Discover (breadth)
- Build a keyword set: core terms, synonyms, EN + ID variants, Boolean combos. Show it briefly.
- Search each source separately: academic connectors/MCP; OpenAlex / Semantic Scholar / Crossref (metadata, citation counts); Google Scholar; DOAJ / CORE / BASE (open access); Garuda (garuda.kemdikbud.go.id) and SINTA for Indonesian journals (mostly OJS, open PDF); Telkom OpenLib for prior Telkom theses.
- Snowball: references and citing papers of the 2-3 strongest hits.
- Deduplicate by DOI/title.

## 2. Screen (abstract level)
- Table: #, author, year, title, venue, DOI/URL, OA? (Y/N/unknown), relevance (high/med/low) + one-line reason.
- Prefer peer-reviewed, reputable/SINTA-indexed venues, recent, cited. Flag predatory-looking journals.
- Let the user approve the shortlist before deep reading if they are present.

## 3. Get full text (in this order)
1. Publisher page if OA (OJS journals, MDPI, PLOS, Frontiers, arXiv, PMC).
2. Unpaywall / CORE / OpenAIRE / Europe PMC / institutional repository (eprints, repository.*.ac.id) for a legal OA copy.
3. Paywalled: the user's own logged-in campus session (Chrome), OR ask the user to download the PDF into the repo's reference folder.
- Never use Sci-Hub, LibGen, or other piracy mirrors.
- If full text is unavailable, mark the entry "abstract-only" and never cite details beyond the abstract.

## 4. Extract (deep read)
For each full-text paper, add a row to the literature matrix:
author-year | aim/RQ | theory/framework | method (design, sample, location, tools) | key findings (with numbers) | limitations | relevance to my RQ | 1-2 short quotes WITH page number | full reference (APA 7).
- Extract from the actual text; mark any inference as "interpretasi".

## 5. Synthesize
- Group by theme/debate, not paper-by-paper. Show agreements, contradictions, method patterns, and the research gap leading to the RQ.
- Every claim in a draft links to a matrix row and page.

## 6. Verify (always last)
- Spot-check every DOI/URL resolves and metadata matches (title, authors, year, venue).
- Each citation's claim is actually supported by the cited page.
- Output a list: verified / abstract-only / needs user check.
- Keep the daftar pustaka file in sync; recommend Zotero.

## Credentials rules
- Prefer OAuth connectors (the user signs in; Claude never sees the password).
- For paywalled sites the user logs in themselves; Claude uses the open session and never types, asks for, stores, or repeats passwords.
- API keys/emails go in the user's local env, never in committed files or chat.
- Do not bulk-download beyond what was asked; respect site terms and rate limits.
