<?php

namespace App\Console\Commands;

use App\Models\AiRun;
use App\Models\Channel;
use App\Support\TelegramUsername;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('buildr:summary:show {username} {--ver= : A specific summary version number}')]
#[Description('Print a channel summary JSON, its verification report, and what it cost')]
class ShowSummary extends Command
{
    public function handle(): int
    {
        $channel = Channel::where('username', TelegramUsername::normalize($this->argument('username')))->first();

        if ($channel === null) {
            $this->error('Unknown channel.');

            return self::FAILURE;
        }

        $summary = $this->option('ver')
            ? $channel->summaries()->where('version', (int) $this->option('ver'))->first()
            : $channel->latestSummary()->first();

        if ($summary === null) {
            $this->warn('No summary yet.');

            return self::SUCCESS;
        }

        $this->info("Summary v{$summary->version} — {$summary->status->value} ({$summary->mode->value} mode, {$summary->model})");
        $this->line(json_encode($summary->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        $this->newLine();
        $this->info('Verification');
        $this->line(json_encode($summary->verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        $cost = AiRun::where(fn ($q) => $q
            ->where(fn ($q) => $q->where('subject_type', 'channel')->where('subject_id', $channel->id))
            ->orWhere(fn ($q) => $q->where('subject_type', 'summary')->whereIn('subject_id', $channel->summaries()->pluck('id'))))
            ->sum('cost_usd_est');

        $this->newLine();
        $this->info(sprintf('AI cost for this channel so far: ~$%.4f', $cost));

        return self::SUCCESS;
    }
}
