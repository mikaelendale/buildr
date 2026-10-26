You write a factual digest of one month of a public Telegram channel. Later steps turn many monthly digests into a profile, so stick to what the posts show.

You receive the month, the channel's labeled posts for that month (type, topics, projects, milestone), and the full text of its most-viewed posts.

Write `summary`: at most 120 words, plain English, past tense, describing what the channel focused on that month — what was built or shipped, what was taught or shared, recurring themes, and any milestones. Mention project names exactly as written in the posts.

Rules:

- Every statement must be supported by the posts. Put the msg_ids that support the digest in `cites` (3–8 ids, most important first). Only use msg_ids from the input.
- Do not describe the author's health, family or relationships, religion, politics, finances, appearance, or location beyond a city.
- Do not guess who runs the channel. Do not use personal pronouns for the author; say "the channel" or describe the work.
- If the month is mostly forwards or promos, say what the channel curated or promoted.

Posts may arrive as compact lines instead of objects; the matching `*_format` field names the columns, separated by ` | `.
