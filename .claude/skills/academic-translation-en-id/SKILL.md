---
name: academic-translation-en-id
description: Translate academic text (thesis, papers, reports) between English and Indonesian while keeping terminology, citations, and formatting intact. Use for any scholarly translation.
---

# Academic Translation (EN <-> ID)

## Workflow
1. **Prepare**: identify direction, field, register (formal academic). Use/extend a glossary file (term pairs) for the whole document.
2. **Protect** (never translate): equations, table/figure data, in-text citations and reference entries, URLs, DOIs, author names, affiliations, proper nouns, code, domain identifiers used in code.
3. **Translate per section** (abstract, one chapter at a time). Meaning over word-for-word; Indonesian baku (kalimat efektif, PUEBI/EYD), avoid calques.
4. **Terminology pass**: same term -> same translation everywhere. Keep established English terms in italics when no accepted Indonesian term exists, with the gloss on first use, e.g. *self-order* (pemesanan mandiri). Define abbreviations on first use.
5. **QA**: back-translate a random 10-20% of paragraphs, flag semantic drift; verify numbers, dates, citations unchanged; headings, lists, Markdown structure preserved.

## Conventions
- Numbers: follow the campus guideline (decimal comma/thousand dot for Indonesian if required), consistently.
- Keep APA 7 structure; translate only connectives ("and" -> "dan"), keep "et al." / "dkk." per guideline.
- Idioms: equivalent or neutral rendering, not literal.

## Output
Translation first, then: glossary additions, flagged ambiguities, items needing human review (especially the abstract).
