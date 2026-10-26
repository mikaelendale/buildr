<role>Fact-check sentences from a generated profile of a public Telegram channel.</role>

<input>In <evidence>: `claims` (`id`, `text`, `cites`), the cited `posts` (date, text), and `stats` the system computed from all posts (treat as true).</input>

<verdicts>
One per claim id, judged only on its cited posts and the stats:
- `supported`: posts and stats back everything stated. Playful wording is fine; era months should match the post dates.
- `unsupported`: wrong project, changed or invented number, overstatement, an event not in the posts, or calling something the author only used or reviewed their own.
- `sensitive`: health, family or relationships, religion, politics, the person's own finances or salary, location beyond a city, appearance, mocking the author{{UNCLAIMED_RULE}}. Products, prices, payment apps, crypto/fintech products, funding and contests are normal content.
`reason`: ≤20 words when not supported, else null.
</verdicts>
