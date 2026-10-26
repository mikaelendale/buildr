<!--
  DRAFT voice examples. Plan §8.5 asks for three hand-written profiles that
  define the voice: rewrite these in your own words before freezing
  prompt_version 1. They are fictional; keep them free of banned topics.
-->

### Example 1 (claimed, a solo app builder)

```json
{
    "hook": "Ships on Fridays, fixes on Saturdays, and has not skipped a week since February.",
    "vibe_tags": ["Builder", "Shipper", "Open Book"],
    "intro": "Selam builds small, sharp tools for Ethiopian freelancers and posts every step in public. Most weeks bring a new feature for Birr Invoice, a screenshot of the dashboard, and an honest note about what broke.",
    "building_now": [
        {
            "project_slug": "birr-invoice",
            "line": "Invoices in birr with Telebirr links baked in, now used by the first paying teams.",
            "cites": [812, 845]
        }
    ],
    "eras": [
        {
            "name": "The Tutorial Year",
            "from": "2023-01",
            "to": "2023-09",
            "story": "The channel started as Laravel tips in Amharic, one short lesson at a time. The lessons got longer, then turned into a mini course.",
            "cites": [12, 88]
        },
        {
            "name": "Building in Public",
            "from": "2023-10",
            "to": "2024-06",
            "story": "The tips gave way to a product. Weekly progress posts tracked Birr Invoice from a sketch to a launch.",
            "cites": [402, 511]
        }
    ],
    "start_here": [
        {
            "msg_id": 511,
            "why": "The launch post, with the whole story of the first version."
        },
        { "msg_id": 88, "why": "The lesson that made the channel take off." }
    ],
    "in_their_words": [
        {
            "msg_id": 845,
            "quote": "Ship the boring version first. Nobody pays for the roadmap."
        }
    ],
    "how_they_work": {
        "text": "Laravel and Livewire on the backend, Figma first for every screen, and a strict Friday release habit.",
        "cites": [402, 845]
    },
    "fun_facts": [
        {
            "text": "Has posted every single week for 31 weeks straight.",
            "source": "stats.current_streak_weeks"
        }
    ]
}
```

### Example 2 (unclaimed, a design resources channel)

```json
{
    "hook": "A daily drip of interface ideas, mostly from people you have not heard of yet.",
    "vibe_tags": ["Curator", "Design", "Daily"],
    "intro": "This channel collects UI patterns, type pairings, and small design tools, usually one per day. About half the posts are original breakdowns of real apps; the rest point to resources worth bookmarking.",
    "building_now": [],
    "eras": [
        {
            "name": "The Screenshot Days",
            "from": "2022-03",
            "to": "2022-12",
            "story": "Early posts were single screenshots with one-line notes. Over the year the notes grew into short teardowns.",
            "cites": [5, 140]
        }
    ],
    "start_here": [
        {
            "msg_id": 140,
            "why": "A clear teardown of a checkout flow that shows the channel's style."
        }
    ],
    "in_their_words": [],
    "how_they_work": null,
    "fun_facts": [
        {
            "text": "Most posts land between 7 and 8 in the morning.",
            "source": "stats.peak_hour"
        }
    ]
}
```

### Example 3 (claimed, an AI tinkerer who teaches)

```json
{
    "hook": "Breaks a new AI tool before breakfast, then writes the guide so you do not have to.",
    "vibe_tags": ["Teacher", "Tinkerer", "AI"],
    "intro": "Dawit tests AI tools on real Amharic text and reports what actually works. The channel mixes hands-on guides, small open-source scripts, and blunt reviews.",
    "building_now": [
        {
            "project_slug": "amharic-ocr-kit",
            "line": "A small toolkit that turns scanned Amharic documents into clean text.",
            "cites": [301]
        }
    ],
    "eras": [
        {
            "name": "Prompt Experiments",
            "from": "2024-02",
            "to": "2024-07",
            "story": "Short posts comparing how different models handle Amharic. Each ended with a ranked list.",
            "cites": [44, 97]
        },
        {
            "name": "Open Toolkit",
            "from": "2024-08",
            "to": "2025-03",
            "story": "The experiments turned into code. The Amharic OCR kit went open source and picked up its first contributors.",
            "cites": [301, 356]
        }
    ],
    "start_here": [
        {
            "msg_id": 97,
            "why": "The model comparison that most readers share."
        },
        { "msg_id": 301, "why": "Where the OCR kit first appears." }
    ],
    "in_their_words": [
        {
            "msg_id": 356,
            "quote": "If it does not work on our language, it does not work yet."
        }
    ],
    "how_they_work": {
        "text": "Python notebooks, lots of side-by-side tests, and a guide written the same day.",
        "cites": [97, 356]
    },
    "fun_facts": [
        {
            "text": "Language mix is 62% Amharic.",
            "source": "stats.language_mix.am"
        }
    ]
}
```
