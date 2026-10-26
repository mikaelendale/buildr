<?php

use App\Services\Ai\SummaryVerifier;

it('accepts quotes copied exactly', function (string $post, string $quote) {
    expect(SummaryVerifier::containsVerbatim($post, $quote))->toBeTrue();
})->with([
    'plain' => ['Shipped refunds today. Next: webhooks.', 'Shipped refunds today.'],
    'typographic quotes' => ['He said "ship it" and we did', 'He said “ship it”'],
    'unicode hyphens' => ['a real-time bot for shops', 'a real‑time bot for shops'],
    'emoji and line breaks dropped' => ["I wish you less —\nless information 📣\nless food 🍆", 'I wish you less, less information, less food'],
    'amharic' => ['ዛሬ አዲስ ባህሪ ለቀቅን።', 'ዛሬ አዲስ ባህሪ'],
]);

it('rejects reworded or partial quotes', function (string $post, string $quote) {
    expect(SummaryVerifier::containsVerbatim($post, $quote))->toBeFalse();
})->with([
    'word changed' => ['I wish you less information', 'I want you less information'],
    'words reordered' => ['less food, less sleep', 'less sleep, less food'],
    'partial word' => ['shipping today', 'ship today'],
    'empty' => ['anything', '  ""  '],
]);
